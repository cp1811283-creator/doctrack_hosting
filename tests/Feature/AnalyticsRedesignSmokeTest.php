<?php

use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use Carbon\Carbon;

/**
 * upload_date is deliberately NOT mass-assignable on DocumentRepository
 * (see its $fillable list) — passing it inside create([...]) is silently
 * dropped and the DB's own CURRENT_TIMESTAMP default (real wall-clock
 * UTC, ignoring Carbon::setTestNow()) fills it in instead. Every test in
 * this file that needs a specific upload_date goes through this helper,
 * which sets it via direct property assignment (bypasses $fillable, same
 * pattern already used in tests/Feature/CalendarDrilldownTest.php)
 * instead of create()'s array.
 */
function analyticsDoc(array $attributes, Carbon $uploadDate): DocumentRepository
{
    $doc = DocumentRepository::create($attributes);
    $doc->upload_date = $uploadDate;
    $doc->save();

    return $doc->fresh();
}

it('renders the redesigned Analytics panel with real KPI/category/backlog data', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // A decided document (approved) — feeds the KPI tiles.
    $decided = analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'analytics-decided.txt', 'file_path' => 'documents/a.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order', 'updated_at' => now(),
    ], now()->subHours(3));

    // A rejected document.
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'analytics-rejected.txt', 'file_path' => 'documents/b.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'rejected', 'ml_category' => 'Purchase Requisition', 'updated_at' => now(),
    ], now()->subHours(2));

    // A still-in-progress document — feeds the backlog snapshot.
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'analytics-backlog.txt', 'file_path' => 'documents/c.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now());

    SlaViolation::create([
        'document_id' => $decided->document_id, 'violation_timestamp' => now(), 'duration_overdue' => 30, 'stage_name' => 'Technical Review',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk();
    $response->assertSee('Approval Rate');
    $response->assertSee('Auto-Approval Rate');
    $response->assertSee('SLA Violation Rate');
    $response->assertSee('Currently in progress');
});

it('serves the analytics panel fragment via AJAX for a given granularity and date', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'panel.txt', 'file_path' => 'documents/panel.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now());

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'month', 'as_of' => now()->toDateString()]));

    $response->assertOk();
    $response->assertSee('analytics-panel-content', false);
    $response->assertSee('data-granularity="month"', false);
});

it('zero-fills the Year tab so every month in range appears, not just active ones', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // Two uploads 8 months apart (both inside the trailing 12-month
    // window), with a silent month exactly between them — the chart must
    // still render one row per month across the whole range, not just the
    // two months something happened (the original bug this fixes). Year
    // is the tab with monthly buckets now — Month buckets by day over a
    // much shorter ~30-day window instead (see
    // AdminController::ANALYTICS_GRANULARITIES's own docblock).
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'gap-start.txt', 'file_path' => 'documents/gs.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now()->subMonths(8));
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'gap-end.txt', 'file_path' => 'documents/ge.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now());

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'year', 'as_of' => now()->toDateString()]));

    $response->assertOk();
    // A zero-activity month exactly between the two uploads must still be
    // present as a bucket (embedded in the chart's data-points payload).
    $silentMonth = now()->subMonths(4)->format('Y-m');
    $response->assertSee($silentMonth);
});

it('zero-fills the Month tab so every day in range appears, not just active ones', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // Two uploads 20 days apart (both inside the trailing ~1-month
    // window), with a silent day exactly between them.
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'day-gap-start.txt', 'file_path' => 'documents/dgs.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now()->subDays(20));
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'day-gap-end.txt', 'file_path' => 'documents/dge.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now());

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'month', 'as_of' => now()->toDateString()]));

    $response->assertOk();
    $silentDay = now()->subDays(10)->format('M j, Y');
    $response->assertSee($silentDay);
});

it('shows the Day tab as a single day broken into hourly buckets, zero-filling silent hours', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 20:00:00'));

    // Two uploads several hours apart on the same day, with a silent hour
    // between them — resolves the old symptom of the chart's real activity
    // always bunching up against the right edge of a rolling multi-day
    // window (see AdminController::ANALYTICS_GRANULARITIES's docblock).
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'hour-start.txt', 'file_path' => 'documents/hs.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now()->copy()->setTime(9, 15));
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'hour-end.txt', 'file_path' => 'documents/he.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now()->copy()->setTime(15, 30));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));

    $response->assertOk();
    // Noon — squarely between the two uploads — must still be present as
    // a zero-activity hourly bucket, not skipped.
    $response->assertSee('12:00');
});

it('summarizes the Day tab KPI tiles from the whole day, not just the most recent hour', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 20:00:00'));

    // Three uploads earlier today, none in the last hour — a KPI tile
    // driven off only the most recent bucket would show 0 uploaded; the
    // whole-day aggregate (AdminController::analyticsAggregateRow()) must
    // show the true total instead.
    foreach ([9, 11, 14] as $hour) {
        analyticsDoc([
            'originator_id' => $originator->user_id, 'title' => "doc-$hour.txt", 'file_path' => "documents/doc-$hour.txt",
            'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
            'global_status' => 'processing', 'ml_category' => 'Job Order',
        ], now()->copy()->setTime($hour, 0));
    }

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));
    $response->assertOk();

    preg_match('/Uploaded<\/p>.*?tabular-nums"[^>]*>(\d+)</s', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2)
        ->and((int) $matches[1])->toBe(3);
});

it('never lets the SLA Violation Rate KPI exceed 100%, even when one document has more violation events than there are decided documents', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 20:00:00'));

    // A single decided document — but with THREE separate violation
    // events logged against it (one per approver who independently blew
    // their SLA on a parallel stage, per the multi-approver workflow).
    // Naively dividing raw violation events (3) by decided documents (1)
    // would read as 300% — the bug this test guards against.
    $decided = analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'multi-violation.txt', 'file_path' => 'documents/mv.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order', 'updated_at' => now(),
    ], now()->subHours(4));
    foreach (['Technical Review', 'Budget Check', 'Final Approval'] as $stage) {
        SlaViolation::create([
            'document_id' => $decided->document_id, 'violation_timestamp' => now()->subHours(1),
            'duration_overdue' => 15, 'stage_name' => $stage,
        ]);
    }

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));
    $response->assertOk();

    preg_match('/SLA VIOLATION RATE<\/p>.*?tabular-nums"[^>]*>([\d.]+)%/is', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $rate = (float) $matches[1];
    expect($rate)->toBeLessThanOrEqual(100.0)
        // With exactly 1 decided document and that document having at
        // least one violation, the rate should read exactly 100%, not 0%
        // (i.e. the fix isn't just clamping/hiding the number).
        ->and($rate)->toBe(100.0);
});

it('excludes auto-approved documents from Avg. Time to Decide, since that gap is never a real human decision time', function () {
    // Regression coverage for a real reported bug (confirmed 2026-10-03):
    // an auto-approved document's upload-to-decision gap is either near-
    // instant (no eligible approver) or the full SLA window (a missed
    // deadline) — folding it into the same average as genuine human
    // decisions produced a number that honestly answered neither question.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 20:00:00'));

    // updated_at is a real Eloquent-managed timestamp — like upload_date,
    // passing it inside create([...]) is silently overwritten by the
    // auto-touch on save(), so it's set afterward via a raw query-builder
    // update() (bypasses Eloquent's timestamp management entirely),
    // confirmed necessary by this test actually failing against the
    // auto-touched value first.
    //
    // A human decision taking 10 minutes.
    $human = analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'human-decided.txt', 'file_path' => 'documents/hd.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order',
    ], now()->copy()->setTime(9, 0));
    DocumentRepository::where('document_id', $human->document_id)->update(['updated_at' => now()->copy()->setTime(9, 10)]);

    // An auto-approval that sat for the better part of a day (a missed
    // SLA window) — would badly skew a combined average upward.
    $auto = analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'auto-approved.txt', 'file_path' => 'documents/aa.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'auto_approved', 'ml_category' => 'Job Order',
    ], now()->copy()->setTime(9, 0));
    DocumentRepository::where('document_id', $auto->document_id)->update(['updated_at' => now()->copy()->setTime(18, 0)]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));
    $response->assertOk();

    preg_match('/AVG\. TIME TO DECIDE<\/p>.*?tabular-nums"[^>]*>([\w\s]+?)</is', $response->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    // 10 minutes — the human decision alone, not blended with the ~9-hour
    // auto-approval gap (which would pull a combined average well past an
    // hour).
    expect(trim($matches[1]))->toBe('10m');
});

it('still plots every period on the chart itself, all-zero ones included', function () {
    // Feature: "View detailed breakdown" (the table this test originally
    // covered, which dropped all-zero rows) was removed from the panel —
    // see analytics-panel.blade.php. The CSV download is the detailed
    // view now, and it deliberately does the OPPOSITE (every period,
    // zero-activity ones included — see AnalyticsPanelDownloadTest.php's
    // own regression coverage for why). This test is narrowed to what's
    // still true: the chart's own data still zero-fills every period for
    // a continuous line, never silently dropping one.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 20:00:00'));

    // One upload at 9 AM — every other hour in the day stays all-zero.
    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'lone-upload.txt', 'file_path' => 'documents/lu.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], now()->copy()->setTime(9, 0));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));

    // The chart's underlying data has all 24 hours (zero-fill for the
    // continuous line) — 3 AM, an all-zero hour, must still appear there
    // (embedded in the SVG's data-points payload).
    $response->assertOk()->assertSee('3:00 AM');
});

it('the chart readout line includes a Violated count, placed after Rejected', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $this->travelTo(Carbon::parse('2026-08-10 10:00:00'));

    // A document that auto-approved via a REAL missed deadline — the
    // only path that logs an SlaViolation row (see SlaService::
    // autoApproveApproverMiss()) — so this is the one document the
    // "Violated" readout should count.
    $violated = analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'violated.txt', 'file_path' => 'documents/v.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'auto_approved', 'ml_category' => 'Job Order', 'updated_at' => now(),
    ], now());
    SlaViolation::create([
        'document_id' => $violated->document_id, 'approver_id' => $approver->user_id,
        'violation_timestamp' => now(), 'duration_overdue' => 15, 'stage_name' => 'Technical Review',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));
    $response->assertOk()->assertSee('Violated');

    // Placed after Rejected in the readout, not between Auto Approved and
    // Rejected — Uploaded/Approved/Auto Approved/Rejected are the parts
    // that sum to the whole, Violated overlaps with Auto Approved instead
    // of being a sibling slice of it.
    $html = $response->getContent();
    expect(strpos($html, 'Rejected'))->toBeLessThan(strpos($html, 'Violated'));
});

it('the readout line defaults to the whole window\'s totals, not whichever single bucket happens to be last', function () {
    // Regression coverage for the real bug this was built to fix: the
    // readout used to default to $latestPoint (literally the final hour
    // of a Day view, or the most recent week/month/year bucket) — almost
    // always empty/misleading on its own, completely disconnected from
    // the KPI tiles' own whole-window aggregate sitting right above it.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $this->travelTo(Carbon::parse('2026-08-10 23:30:00')); // late in the day — the final hour bucket is empty

    foreach ([9, 11, 14] as $hour) {
        analyticsDoc([
            'originator_id' => $originator->user_id, 'title' => "gap-doc-$hour.txt", 'file_path' => "documents/gd-$hour.txt",
            'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
            'global_status' => 'processing', 'ml_category' => 'Job Order',
        ], now()->copy()->setTime($hour, 0));
    }

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day', 'as_of' => now()->toDateString()]));

    // The final hour (11 PM) has zero uploads — a bug reverted to showing
    // that literal 0 by default. The readout must show the real total (3)
    // instead, matching the Uploaded KPI tile right above it.
    preg_match('/data-readout-uploaded>(\d+)</', $response->getContent(), $matches);
    expect((int) $matches[1])->toBe(3);
});

it('Week/Month/Year each cover exactly the trailing window their name promises, today counted as day one', function () {
    $admin = User::factory()->admin()->create();
    $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));

    // Week: today back to 6 days ago = 7 real days total.
    $week = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'week']));
    $week->assertOk()->assertSee('Analytics for Oct 3, 2026 – Oct 9, 2026');

    // Month: exactly one calendar month back from today (Sep 9), plus one
    // day so today itself is the 1st day counted backward, same symmetry
    // as Week above — not the 1st of the current month.
    $month = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'month']));
    $month->assertOk()->assertSee('Analytics for Sep 10, 2026 – Oct 9, 2026');

    // Year: exactly one calendar year back from today, same +1 day
    // symmetry — not Jan 1 of the current year.
    $year = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'year']));
    $year->assertOk()->assertSee('Analytics for Oct 10, 2025 – Oct 9, 2026');
});

it('the Year tab\'s current month is never silently dropped from the bucket list', function () {
    // Regression coverage for a real confirmed bug: $since now lands on
    // the 10th of a month (not the 1st, per the "today counted as day
    // one" window math above), so stepping the bucket cursor by exactly 1
    // month, 12 times, and comparing against $until's exact datetime
    // overshot past it by a day on the final step — silently dropping the
    // entire current month (and every real document uploaded in it) from
    // the chart and the KPI tiles alike.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));

    analyticsDoc([
        'originator_id' => $originator->user_id, 'title' => 'this-month.txt', 'file_path' => 'documents/tm.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'processing', 'ml_category' => 'Job Order',
    ], Carbon::parse('2026-10-08 22:00:00'));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'year']));

    $response->assertOk()->assertSee('2026-10');
    preg_match('/data-readout-uploaded>(\d+)</', $response->getContent(), $matches);
    expect((int) $matches[1])->toBe(1);
});
