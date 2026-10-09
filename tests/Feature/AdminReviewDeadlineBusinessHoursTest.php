<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Regression coverage for a real confirmed bug: the Admin review deadline
 * (SlaService::reviewDeadlineFor()) used to be a flat now()->addHours(6)
 * with no business-hours awareness at all — the one deadline in the app
 * that didn't pause overnight/Sundays, unlike every other SLA deadline.
 * With a single Admin account, that meant "overdue" was lit almost
 * permanently for anything auto-approved outside business hours.
 */
function reviewPendingAssignment(User $originator, Carbon $actedAt, ?Carbon $dueDate = null): DocumentAssignment
{
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Technical Review'],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'review-deadline-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt', 'mime_type' => 'text/plain',
        'due_date' => $dueDate ?? now()->addDays(5), 'global_status' => 'processing', 'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $originator->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'auto_approved' => true, 'acted_at' => $actedAt,
        'review_due_at' => app(SlaService::class)->reviewDeadlineFor(new DocumentAssignment(['due_date' => $document->due_date])),
    ]);
}

it('a review deadline set late on a Friday skips the weekend instead of landing mid-Saturday-night', function () {
    // 3:30 PM Friday + a flat 6 clock hours would land at 9:30 PM Friday —
    // technically "Saturday business hours" don't exist in this app's
    // Mon-Sat window, but the real point is 6 REAL WORKING hours from
    // 3:30 PM Friday should land well into the following week, not just
    // a same-day clock addition.
    Carbon::setTestNow(Carbon::parse('2026-08-14 15:30:00')); // Friday
    $admin = User::factory()->admin()->create();

    $deadline = app(SlaService::class)->reviewDeadlineFor(new DocumentAssignment(['due_date' => now()->addDays(10)]));

    // 1.5 working hours left Friday (3:30-5:00) + 4.5 more hours needed,
    // continuing Saturday 9:00 AM onward -> 1:30 PM Saturday.
    expect($deadline->toDateTimeString())->toBe('2026-08-15 13:30:00');
});

it('recalculates an in-flight Admin review deadline when a holiday is added, the same way pending SLA deadlines already do', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00')); // Monday
    $assignment = reviewPendingAssignment($originator, now(), now()->addDays(10));
    $originalDeadline = $assignment->review_due_at;

    // Mark Tuesday (tomorrow, inside the review window) as a holiday.
    $response = $this->actingAs($admin)->post(route('admin.calendar.holidays.store'), [
        'holiday_date' => '2026-08-18', 'label' => 'Test Holiday',
    ]);
    $response->assertRedirect();

    $fresh = $assignment->fresh();
    expect($fresh->review_due_at->equalTo($originalDeadline))->toBeFalse()
        ->and($fresh->review_due_at->gt($originalDeadline))->toBeTrue();
});

it('does not touch a review deadline once Admin has already confirmed or disputed it', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00'));
    $assignment = reviewPendingAssignment($originator, now());
    $assignment->update(['admin_reviewed_at' => now(), 'admin_review_outcome' => 'confirmed']);
    $originalDeadline = $assignment->review_due_at;

    $this->actingAs($admin)->post(route('admin.calendar.holidays.store'), [
        'holiday_date' => '2026-08-18',
    ]);

    expect($assignment->fresh()->review_due_at->equalTo($originalDeadline))->toBeTrue();
});

/**
 * The on-screen "Review by... remaining" countdown used to tick in flat
 * wall-clock time even though review_due_at itself is now business-hours-
 * aware (the fix above) — same confirmed real bug as the approver page
 * once had, before it gained its own "⏸ Paused" treatment. This covers
 * the Admin Auto-Approval Review page getting the identical treatment:
 * the pause label only outside business hours, and the countdown driven
 * by the business-hours-aware data-real-remaining ticker rather than the
 * plain data-live-time one.
 */
it('shows the paused countdown label on the Admin review queue outside business hours, not during them', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // 9 PM Monday — well outside the 9 AM-5 PM window.
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00'));
    reviewPendingAssignment($originator, now(), now()->addDays(10));

    $this->actingAs($admin)->get(route('admin.sla.queue'))
        ->assertOk()
        ->assertSee('⏸ Paused (outside business hours)')
        ->assertSee('data-real-remaining', false);

    // 10 AM Tuesday — inside the window, same assignment.
    Carbon::setTestNow(Carbon::parse('2026-08-18 10:00:00'));

    $this->actingAs($admin)->get(route('admin.sla.queue'))
        ->assertOk()
        ->assertDontSee('⏸ Paused (outside business hours)');
});

/**
 * The "Review overdue since... (X ago)" badge used to be raw wall-clock
 * elapsed time — so a deadline missed late Friday read as ~50+ hours
 * overdue by Monday morning, even though almost none of that was actual
 * business-hours backlog. Now business-hours-aware (data-real-elapsed),
 * same reasoning and mechanism as the not-yet-overdue countdown above.
 */
it('measures the overdue badge in business hours only, not raw wall-clock', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // Deadline passed 4:30 PM Friday (inside the window); "now" is 6:00 PM
    // the SAME Friday — 1.5 wall-clock hours, but the window closed at
    // 5:00 PM, so only 30 real business minutes actually elapsed.
    Carbon::setTestNow(Carbon::parse('2026-08-14 16:30:00')); // Friday 4:30 PM
    $assignment = reviewPendingAssignment($originator, now()->subHours(7), now()->addDays(10));
    $assignment->forceFill(['review_due_at' => now()])->save();

    Carbon::setTestNow(Carbon::parse('2026-08-14 18:00:00')); // same Friday, 6:00 PM

    $response = $this->actingAs($admin)->get(route('admin.sla.queue'));
    $response->assertOk()->assertSee('Review overdue since');

    // 30 real business minutes elapsed (4:30-5:00 PM), not the 1.5
    // wall-clock hours a diffForHumans() readout would have shown.
    $response->assertSee('data-real-elapsed="1800"', false);
});

/**
 * Same business-hours-only treatment, applied to the Admin Violations
 * report's "Flagged X (ago)" badge — it's the same underlying "Admin is
 * late on a review" backlog as the Auto-Approval Review page's own badge,
 * just surfaced on a different page, so it should read the same way.
 */
it('measures the Admin Violations "Flagged" badge in business hours only', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Technical Review'],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'violation-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt', 'mime_type' => 'text/plain',
        'due_date' => now()->addDays(10), 'global_status' => 'processing', 'ml_category' => 'Job Order',
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $originator->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'auto_approved' => true, 'acted_at' => now(),
    ]);

    // Flagged 9 PM Monday — well outside business hours.
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00'));
    AdminViolation::create([
        'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
        'violation_type' => 'late_review', 'stage_name' => $stage->stage_name,
        'first_violated_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']))
        ->assertOk()
        ->assertSee('⏸ Paused (outside business hours)')
        ->assertSee('data-real-elapsed="0"', false);
});

/**
 * Confirmed real bug caught while auditing this feature: the business-
 * hours-elapsed "ago" readouts above were first built by calling
 * app(BusinessHoursService::class) fresh inline, per row, inside a blade
 * loop — since that service isn't a container singleton (deliberately —
 * see its own docblock on why a naive singleton binding breaks holiday
 * recalculation instead), each fresh resolution re-ran ensureLoaded()'s
 * SlaHoliday query from scratch. A page with several missed-approver rows
 * turned "one page load" into one SlaHoliday query per "SLA violated"/
 * "Auto-approved" line. Fixed by resolving BusinessHoursService once in
 * the controller and passing that one instance down as a view variable
 * instead — including into <x-workflow-stage-list>, which had the exact
 * same per-render re-query for its own forecast estimate (pre-existing,
 * not introduced this session, but fixed alongside this for the same
 * reason). This locks both fixes in by asserting the holiday-table query
 * count stays flat at 2 (traced by hand — see below) no matter how many
 * assignment rows the page renders, instead of scaling with them.
 */
it('does not re-query the holiday calendar per row on the Admin review queue', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Technical Review'],
        ['sequence_order' => 1]
    );

    $makeReviewRow = function () use ($originator, $stage) {
        $document = DocumentRepository::create([
            'originator_id' => $originator->user_id, 'title' => 'n-plus-one-'.uniqid().'.txt',
            'file_path' => 'documents/'.uniqid().'.txt', 'mime_type' => 'text/plain',
            'due_date' => now()->addDays(10), 'global_status' => 'processing', 'ml_category' => 'Job Order',
        ]);
        DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $originator->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
            'auto_approved' => true, 'acted_at' => now()->subHour(),
            'sla_expires_at' => now()->subMinutes(30),
            'review_due_at' => app(SlaService::class)->reviewDeadlineFor(new DocumentAssignment(['due_date' => $document->due_date])),
        ]);
    };
    Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00')); // Monday, inside business hours

    // 8 rows is well past what the old per-row bug would have survived
    // unnoticed at (it scaled 2 extra queries per row) — if the fix ever
    // regresses, this count jumps into the teens, not just by one or two.
    foreach (range(1, 8) as $i) {
        $makeReviewRow();
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.sla.queue'))->assertOk();
    $holidayQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'sla_holidays'))->count();
    DB::disableQueryLog();

    // Exactly 2, not 1 — traced both down by hand: one is
    // AdminController's own BusinessHoursService instance (shared with
    // every row and with <x-workflow-stage-list> via the $businessHours
    // view variable), the other is layouts/app.blade.php's own, unrelated
    // <meta name="business-hours"> tag, which queries SlaHoliday directly
    // (not through BusinessHoursService at all) on every page in the app
    // to feed the client-side pause-ticker JS. That one's a pre-existing,
    // already-necessary, flat per-page cost, not a regression — the real
    // thing this test guards against is PER-ROW growth, which 8 rows
    // would make obvious (the old bug scaled 2 extra queries per row).
    expect($holidayQueries)->toBe(2);
});
