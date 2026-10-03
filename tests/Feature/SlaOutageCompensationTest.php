<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaOutageWindow;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Covers SlaService::detectOutage()/compensateForOutage() — the "the
 * system itself was down, don't penalize approvers for it" mechanism.
 * All test times land on a Monday within 9-5 business hours (config/
 * sla.php defaults) unless a test deliberately needs otherwise.
 */
function pendingAssignmentAt(Carbon $slaExpiresAt, Carbon $dueDate): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    // firstOrCreate, not create — this helper can be (and is) called more
    // than once within a single test to set up several assignments, and a
    // real unique constraint on active category-wide stages (added
    // 2026-10-03, after a genuine production duplicate-stage bug) now
    // correctly catches what a second create() call here would be: a real
    // duplicate "Job Order Technical Review" stage.
    $stage = WorkflowStage::firstOrCreate(['document_category' => 'Job Order', 'stage_name' => 'Technical Review'], ['sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'outage-test.txt',
        'file_path' => 'documents/outage-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => $dueDate,
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    return DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $dueDate,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => $slaExpiresAt,
    ]);
}

beforeEach(function () {
    Cache::forget('sla_heartbeat_last_seen');
    // A known Monday, well clear of any holiday, so business-hours math is predictable.
    Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00')); // Monday
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not detect an outage on the very first heartbeat — nothing to compare against yet', function () {
    $outage = app(SlaService::class)->detectOutage();

    expect($outage)->toBeNull();
    expect(Cache::get('sla_heartbeat_last_seen'))->not->toBeNull();
});

it('does not treat a short gap as an outage', function () {
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(now()->addMinutes(3)); // well under the 10-minute floor

    $outage = app(SlaService::class)->detectOutage();

    expect($outage)->toBeNull();
    expect(SlaOutageWindow::count())->toBe(0);
});

it('does not treat the scheduler\'s own healthy 5-minute tick as an outage', function () {
    // sla:check runs every 5 minutes, so a perfectly healthy scheduler produces a
    // 5:00-5:02 gap between heartbeats. Flagging that (as the old 5-minute floor
    // did) recorded a fake outage on every tick and kept pushing deadlines forward.
    $service = app(SlaService::class);
    $service->detectOutage();

    foreach ([5 * 60 + 2, 5 * 60, 5 * 60 + 1, 9 * 60] as $gapSeconds) {
        Carbon::setTestNow(now()->addSeconds($gapSeconds));
        expect($service->detectOutage())->toBeNull();
    }

    expect(SlaOutageWindow::count())->toBe(0);
});

it('never extends a pending deadline across repeated healthy scheduler ticks', function () {
    $service = app(SlaService::class);
    $service->detectOutage();
    $assignment = pendingAssignmentAt(now()->addMinutes(10), now()->addDays(2)); // Urgent — the exact case that used to slide forward

    $originalExpiry = $assignment->sla_expires_at->copy();

    foreach (range(1, 6) as $tick) {
        Carbon::setTestNow(now()->addMinutes(5)->addSeconds(1));
        $service->checkForOutageRecovery();
    }

    expect($assignment->fresh()->sla_expires_at->equalTo($originalExpiry))->toBeTrue();
    expect(SlaOutageWindow::count())->toBe(0);
});

it('detects a real outage once the gap exceeds the floor, and records the business minutes actually lost', function () {
    app(SlaService::class)->detectOutage(); // establishes baseline at 09:00
    Carbon::setTestNow(now()->addMinutes(40)); // 09:40 — 40 real minutes, all inside business hours

    $outage = app(SlaService::class)->detectOutage();

    expect($outage)->not->toBeNull();
    expect($outage->business_minutes_lost)->toBe(40);
});

it('only counts the portion of an outage that actually overlapped business hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 16:56:00')); // Monday 4:56pm
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(now()->addMinutes(20)); // 5:16pm — outage ran past closing time

    $outage = app(SlaService::class)->detectOutage();

    // Only 4:56-5:00pm (4 minutes) was actually working time — the rest was after close.
    expect($outage->business_minutes_lost)->toBe(4);
});

it('extends the deadline of an Urgent assignment, but leaves a comfortable one untouched', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(now()->addMinutes(30));

    $urgent = pendingAssignmentAt(now()->addMinutes(10), now()->addDays(5)); // 10 min left = Urgent
    $comfortable = pendingAssignmentAt(now()->addHours(5), now()->addDays(5)); // 5 hours left = not Urgent/Normal

    $outage = app(SlaService::class)->detectOutage();
    expect($outage)->not->toBeNull();

    $sla = app(SlaService::class);
    $reflection = new ReflectionMethod($sla, 'compensateForOutage');
    $reflection->setAccessible(true);
    $count = $reflection->invoke($sla, $outage);

    expect($count)->toBe(1);
    expect($urgent->fresh()->sla_expires_at)->not->toEqual($urgent->sla_expires_at);
    expect($comfortable->fresh()->sla_expires_at->equalTo($comfortable->sla_expires_at))->toBeTrue();
    expect(NotificationRecord::where('recipient_id', $urgent->user_id)->exists())->toBeTrue();
    expect(NotificationRecord::where('recipient_id', $comfortable->user_id)->exists())->toBeFalse();
    expect(AuditLog::where('action_type', 'sla_outage_compensation')->exists())->toBeTrue();
});

it('applies the 30-minute fairness floor when the strict calculation would leave a razor-thin window', function () {
    // Outage from 4:56pm to 5:16pm Monday — only 4 working minutes lost, all right at closing time.
    Carbon::setTestNow(Carbon::parse('2026-09-14 16:56:00'));
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(Carbon::parse('2026-09-14 17:16:00'));
    $outage = app(SlaService::class)->detectOutage();
    expect($outage->business_minutes_lost)->toBe(4);

    // Assignment's deadline was already due right when the outage started — Urgent.
    $assignment = pendingAssignmentAt(Carbon::parse('2026-09-14 17:00:00'), now()->addDays(5));

    $sla = app(SlaService::class);
    $reflection = new ReflectionMethod($sla, 'compensateForOutage');
    $reflection->setAccessible(true);
    $reflection->invoke($sla, $outage);

    // Strict math would land 4 minutes into the next working day (9:04am Tuesday).
    // The 30-minute floor (measured from when the outage ended, 5:16pm -> next
    // working moment 9:00am Tuesday + 30min) wins instead: 9:30am.
    expect($assignment->fresh()->sla_expires_at->format('Y-m-d H:i'))->toBe('2026-09-15 09:30');
});

it('never extends a deadline past the assignment\'s own due date', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(now()->addMinutes(30));

    $dueDate = now()->addMinutes(20); // due date arrives before the floor/strict compensation would
    $assignment = pendingAssignmentAt(now()->addMinutes(5), $dueDate);

    $outage = app(SlaService::class)->detectOutage();
    $sla = app(SlaService::class);
    $reflection = new ReflectionMethod($sla, 'compensateForOutage');
    $reflection->setAccessible(true);
    $reflection->invoke($sla, $outage);

    expect($assignment->fresh()->sla_expires_at->equalTo($dueDate))->toBeTrue();
});

it('detects and compensates an outage on the first authenticated request after recovery, not just via the scheduled sweep', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
    app(SlaService::class)->detectOutage(); // baseline heartbeat, as if the scheduler just ran

    $assignment = pendingAssignmentAt(now()->addMinutes(10), now()->addDays(5)); // Urgent

    Carbon::setTestNow(now()->addMinutes(30)); // the "outage" — nothing ran in between

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.dashboard'));

    expect(SlaOutageWindow::count())->toBe(1);
    expect($assignment->fresh()->sla_expires_at)->not->toEqual($assignment->sla_expires_at);
});

it('does not run the outage check for an unauthenticated request', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
    app(SlaService::class)->detectOutage();
    Carbon::setTestNow(now()->addMinutes(30));

    $this->get(route('login'));

    expect(SlaOutageWindow::count())->toBe(0);
});
