<?php

namespace App\Services;

use App\Models\SlaHoliday;
use Carbon\Carbon;

/**
 * BusinessHoursService
 * ---------------------
 * Section 1: Operational Window Controls & Holiday Management. Computes
 * business-hours-aware timestamps against the fixed daily working window
 * (config('sla.php') — 9-5, Mon-Sat by default, no longer admin-editable)
 * and non-working dates (SlaHoliday), which are treated identically to a
 * non-working weekday.
 *
 * Settings are lazy-loaded on first actual use, not in the constructor —
 * this service is resolved fresh per request via normal DI, so there's no
 * staleness risk within a request, and deliberately no Cache:: usage
 * (CACHE_STORE=database has no `cache` table migrated in this app, same
 * latent gap the queue tables had). Deliberately NOT bound as a container
 * singleton either, tempting as that looks for cutting down repeated
 * ensureLoaded() queries (see the several admin views that resolve this
 * per-row in a loop) — a singleton's cached $holidayDates would silently
 * go stale the moment a holiday is added/removed mid-request, and in
 * Laravel's own test HTTP client, multiple simulated requests within one
 * test method share the same container, so a singleton resolved early in
 * a test (e.g. computing a deadline) would still be answering with
 * pre-holiday data after a later request in the SAME test adds one —
 * confirmed by hand: binding this as a singleton broke
 * AdminReviewDeadlineBusinessHoursTest's holiday-recalculation case this
 * exact way. Callers that need to avoid repeated queries within a single
 * render should resolve this once themselves and pass the instance down,
 * not rely on a global binding. Lazy loading specifically matters
 * because Laravel's console kernel auto-discovers every command in
 * app/Console/Commands at boot — including ones that depend on this
 * service several layers down (CheckParallelSlas -> SlaService ->
 * WorkflowService) — just to register them, before any actual command
 * (including `migrate` itself) runs. An eager constructor query here
 * would hit the database for a table that may not exist yet on a brand
 * new install or test database, before the first migration has even had
 * a chance to run.
 *
 * The `due_date` an Originator supplies is NEVER shifted by this service —
 * it's the hard wall-clock ceiling and is only ever used elsewhere as a
 * post-hoc clamp on top of whatever this service computes. This service
 * only governs how an SLA *duration* (e.g. "45 minutes") is distributed
 * forward from "now" across business time.
 *
 * DST note: config('app.timezone') is hardcoded to Asia/Manila, which
 * observes no DST, so this is a non-issue today. The algorithm is
 * DST-safe by construction regardless — it only compares wall-clock
 * date/time components via Carbon's timezone-aware methods, never raw
 * UTC-offset arithmetic.
 */
class BusinessHoursService
{
    /** Trip-wire against misconfiguration (e.g. empty working_days or holiday-flooded calendar), not a real limit. */
    private const MAX_ITERATIONS = 400;

    private ?array $workingDays = null;

    private ?string $workStartTime = null;

    private ?string $workEndTime = null;

    /** ['Y-m-d' => true, ...] for O(1) lookup. */
    private ?array $holidayDates = null;

    /** Loads holidays from the database on first actual use — see class docblock for why this can't happen in the constructor. The working window itself is a fixed config value now, not admin-editable, so no query is needed for that part. */
    private function ensureLoaded(): void
    {
        if ($this->workingDays !== null) {
            return;
        }

        $this->workingDays = config('sla.default_working_days');
        $this->workStartTime = config('sla.default_work_start');
        $this->workEndTime = config('sla.default_work_end');

        $this->holidayDates = SlaHoliday::pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip()
            ->all();
    }

    public function isWorkingDay(Carbon $date): bool
    {
        $this->ensureLoaded();

        return in_array($date->dayOfWeek, $this->workingDays, true)
            && ! isset($this->holidayDates[$date->toDateString()]);
    }

    /**
     * True if $moment falls inside an actual working window (working day,
     * between start/end time) — i.e. the SLA clock is actively progressing
     * right now, as opposed to frozen overnight/weekend/holiday time.
     * Used purely for UI clarity (see components using this to explain why
     * a countdown looks longer than the underlying business-hours budget).
     */
    public function isWithinWorkingWindow(Carbon $moment): bool
    {
        return $this->isWorkingDay($moment)
            && $moment->gte($this->startOfWindow($moment))
            && $moment->lt($this->endOfWindow($moment));
    }

    /**
     * If $from already falls inside a working window, returns it
     * unchanged. Otherwise advances to the start of the next working
     * window (skipping non-working weekdays and holiday dates).
     */
    public function nextWorkingMoment(Carbon $from): Carbon
    {
        $cursor = $from->copy();

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            if (! $this->isWorkingDay($cursor)) {
                $cursor = $this->startOfWindow($cursor->copy()->addDay());

                continue;
            }

            $start = $this->startOfWindow($cursor);
            $end = $this->endOfWindow($cursor);

            if ($cursor->lt($start)) {
                return $start;
            }
            if ($cursor->gte($end)) {
                $cursor = $this->startOfWindow($cursor->copy()->addDay());

                continue;
            }

            return $cursor;
        }

        throw new \RuntimeException(
            'BusinessHoursService: no working day found within '.self::MAX_ITERATIONS.
            ' days — check sla_settings.working_days / sla_holidays for misconfiguration.'
        );
    }

    /**
     * Adds $minutes of business time to $from, skipping evenings,
     * non-working weekdays, and holiday dates, returning the resulting
     * absolute deadline.
     */
    public function addBusinessMinutes(Carbon $from, int $minutes): Carbon
    {
        $cursor = $this->nextWorkingMoment($from);

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            $endOfToday = $this->endOfWindow($cursor);
            $availableToday = $cursor->diffInMinutes($endOfToday, false);

            if ($minutes <= $availableToday) {
                return $cursor->copy()->addMinutes($minutes);
            }

            $minutes -= $availableToday;
            $cursor = $this->nextWorkingMoment($this->startOfWindow($cursor->copy()->addDay()));
        }

        throw new \RuntimeException(
            'BusinessHoursService: could not resolve a deadline within '.self::MAX_ITERATIONS.
            ' working-day iterations — check sla_settings.working_days / sla_holidays for misconfiguration.'
        );
    }

    /**
     * The mirror image of addBusinessMinutes() above: instead of "given a
     * budget, find the deadline," this is "given a deadline, find the
     * real remaining budget from $from" — sums only the seconds between
     * $from and $until that fall inside actual working windows, skipping
     * evenings, non-working weekdays, and holiday dates, instead of a raw
     * wall-clock diff (which can look wildly inflated whenever the gap
     * crosses a non-working stretch). Returns 0 once $until has already
     * passed.
     */
    public function businessSecondsRemaining(Carbon $from, Carbon $until): int
    {
        if ($until->lessThanOrEqualTo($from)) {
            return 0;
        }

        $cursor = $this->nextWorkingMoment($from->copy());
        $totalSeconds = 0;

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            if ($cursor->greaterThanOrEqualTo($until)) {
                break;
            }

            $endOfToday = $this->endOfWindow($cursor);
            $segmentEnd = $until->lessThan($endOfToday) ? $until : $endOfToday;

            $totalSeconds += $cursor->diffInSeconds($segmentEnd);

            if ($until->lessThanOrEqualTo($endOfToday)) {
                break;
            }

            $cursor = $this->nextWorkingMoment($this->startOfWindow($cursor->copy()->addDay()));
        }

        return $totalSeconds;
    }

    /**
     * Section 1 (extended): if $dueDate's calendar day isn't a working day
     * (non-working weekday or an admin-marked holiday), advances the DATE —
     * never the time-of-day — forward until it lands on one. A due date is
     * an external human commitment, not an internal review-time budget, so
     * unlike addBusinessMinutes() this only ever cares about the calendar
     * day, never what hour it falls at.
     */
    public function nextWorkingDueDate(Carbon $dueDate): Carbon
    {
        $adjusted = $dueDate->copy();

        for ($i = 0; $i <= self::MAX_ITERATIONS; $i++) {
            if ($this->isWorkingDay($adjusted)) {
                return $adjusted;
            }
            $adjusted->addDay();
        }

        throw new \RuntimeException(
            'BusinessHoursService: no working day found within '.self::MAX_ITERATIONS.
            ' days while adjusting a due date — check sla_settings.working_days / sla_holidays for misconfiguration.'
        );
    }

    /**
     * How many working minutes actually fell inside [$outageStart,
     * $outageEnd] — e.g. an outage from 4:56pm to 5:16pm only "costs" 4
     * minutes if the working day ends at 5pm, since the other 16 weren't
     * going to be worked anyway. Reuses businessSecondsRemaining() (the
     * same "sum only real working seconds between two points" logic
     * already used for the live SLA countdown) rather than a separate
     * calculation, so this can never disagree with it.
     */
    public function businessMinutesLostToOutage(Carbon $outageStart, Carbon $outageEnd): int
    {
        return (int) ceil($this->businessSecondsRemaining($outageStart, $outageEnd) / 60);
    }

    private function startOfWindow(Carbon $date): Carbon
    {
        $this->ensureLoaded();

        return $date->copy()->setTimeFromTimeString($this->workStartTime);
    }

    private function endOfWindow(Carbon $date): Carbon
    {
        $this->ensureLoaded();

        return $date->copy()->setTimeFromTimeString($this->workEndTime);
    }
}
