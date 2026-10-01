<?php

namespace App\Services;

use App\Models\DocumentAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Plain historical reporting — who's fastest, which department is
 * fastest, which document category moves fastest. Pure averaging over
 * data that already exists, no prediction and no trained model (compare
 * ApprovalForecastService, which predicts a single document's future
 * approval time and — unlike this — is a genuine ML regression problem).
 *
 * Every ranking here excludes auto-approved assignments for the same
 * reason ApprovalForecastService does: an auto-approval measures how
 * long the SLA deadline happened to be, not how fast a person actually
 * decided, so mixing it in would misrepresent a real approver/department's
 * speed.
 */
class PerformanceInsightsService
{
    /** Below this many real decisions, an average is just noise from one lucky/unlucky case, not a real ranking signal. */
    private const MIN_DECISIONS = 3;

    private ?Collection $decisionsCache = null;

    public function __construct(private BusinessHoursService $businessHours) {}

    public function fastestApprovers(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->groupBy('user_id'),
            fn (Collection $rows) => $rows->first()->approver_name,
            $limit,
            roleFor: $this->approverRoleLabel(...)
        );
    }

    public function fastestDepartments(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->filter(fn ($row) => $row->department)->groupBy('department'),
            fn (Collection $rows) => $rows->first()->department,
            $limit
        );
    }

    public function fastestCategories(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->groupBy('ml_category'),
            fn (Collection $rows) => $rows->first()->ml_category,
            $limit
        );
    }

    /**
     * Feature: a Fastest/Slowest toggle on the Performance Insights page —
     * same MIN_DECISIONS-filtered averages as the fastest* methods above,
     * just sorted the other way (see rank()'s $descending param), so
     * "slowest" is never a different, less-trustworthy calculation, only
     * the opposite end of the exact same ranking.
     */
    public function slowestApprovers(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->groupBy('user_id'),
            fn (Collection $rows) => $rows->first()->approver_name,
            $limit,
            descending: true,
            roleFor: $this->approverRoleLabel(...)
        );
    }

    public function slowestDepartments(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->filter(fn ($row) => $row->department)->groupBy('department'),
            fn (Collection $rows) => $rows->first()->department,
            $limit, true
        );
    }

    public function slowestCategories(int $limit = 10): Collection
    {
        return $this->rank(
            $this->decisions()->groupBy('ml_category'),
            fn (Collection $rows) => $rows->first()->ml_category,
            $limit, true
        );
    }

    /**
     * Every real (human, non-auto-approved) decision ever made, with the
     * business-hours-aware elapsed time already computed per row — same
     * "sum only real working seconds" reasoning as every other elapsed-time
     * calculation in this app (see BusinessHoursService), so a decision
     * made Monday morning after sitting since Friday doesn't read as
     * "took 2 days." Fetched once per request and reused across all three
     * rankings above, which just group this same raw material differently.
     */
    private function decisions(): Collection
    {
        if ($this->decisionsCache !== null) {
            return $this->decisionsCache;
        }

        return $this->decisionsCache = DocumentAssignment::query()
            ->join('users', 'document_assignments.user_id', '=', 'users.user_id')
            ->join('document_repository', 'document_assignments.document_id', '=', 'document_repository.document_id')
            ->whereNotNull('document_assignments.acted_at')
            ->where('document_assignments.auto_approved', false)
            ->get([
                'document_assignments.user_id',
                'document_assignments.created_at',
                'document_assignments.acted_at',
                'users.full_name as approver_name',
                'users.department',
                'users.level as approver_level',
                'document_repository.ml_category',
            ])
            ->map(function ($row) {
                $row->elapsed_seconds = $this->businessHours->businessSecondsRemaining(
                    Carbon::parse($row->created_at),
                    Carbon::parse($row->acted_at)
                );

                return $row;
            });
    }

    /**
     * @param  \Closure(Collection): string  $labelFor
     * @param  ?\Closure(Collection): ?string  $roleFor  Only passed by the Approvers rankings — Departments/
     *                                                   Categories have no per-row role concept, so 'role' stays
     *                                                   null for those and the UI just doesn't render a role tag.
     */
    private function rank(Collection $grouped, \Closure $labelFor, int $limit, bool $descending = false, ?\Closure $roleFor = null): Collection
    {
        return $grouped
            // Requires MIN_DECISIONS GENUINELY non-zero readings, not just
            // MIN_DECISIONS total with one real one mixed in — a decision
            // measures 0 whenever it was both routed AND decided entirely
            // outside business hours (see ApprovalTimeMlService::
            // trainFor()'s identical guard). This single check already
            // implies "at least MIN_DECISIONS total" too (a non-zero count
            // can never exceed the total), so it replaces what used to be
            // two separate filters. A misleading near-0 "fastest" entry,
            // mostly built from zero-contaminated readings, is worse than
            // just excluding it until enough real business-hours data
            // exists.
            ->filter(fn (Collection $rows) => $rows->filter(fn ($row) => $row->elapsed_seconds > 0)->count() >= self::MIN_DECISIONS)
            ->map(fn (Collection $rows, $key) => [
                'key' => $key,
                'label' => $labelFor($rows),
                'role' => $roleFor?->__invoke($rows),
                'avg_seconds' => (int) round($rows->avg('elapsed_seconds')),
                'decisions_count' => $rows->count(),
            ])
            ->when($descending, fn (Collection $c) => $c->sortByDesc('avg_seconds'), fn (Collection $c) => $c->sortBy('avg_seconds'))
            ->take($limit)
            ->values();
    }

    /**
     * Mirrors User::displayRole()'s approver branch exactly (always
     * "approver" role in this context — every row here is a real decision
     * someone with an assignment made) — a user_id from a raw join
     * row isn't a hydrated User model, so this can't just call
     * $user->displayRole() without an extra query per distinct approver.
     * Answers the "are Head Approvers mixed in with Staff Approvers here"
     * question directly in the UI instead of leaving it invisible.
     */
    private function approverRoleLabel(Collection $rows): string
    {
        return $rows->first()->approver_level === 'head' ? 'Head Approver' : 'Staff Approver';
    }
}
