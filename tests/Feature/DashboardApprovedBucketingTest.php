<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;

/**
 * An auto-approved document's workflow routing is done, but it still owes
 * an Admin a review — the Control Center counts it as "In Progress" until
 * that review actually happens, not "Approved", even though no approver
 * is left waiting on it. See AdminController::awaitingAdminReview().
 */
function bucketingDoc(User $originator, bool $autoApproved, bool $reviewed = false, string $title = 'bucket.txt', bool $disputed = false): DocumentRepository
{
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Only Stage'],
        ['sequence_order' => 1]
    );

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => $title, 'file_path' => 'documents/'.$title,
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => $autoApproved ? 'auto_approved' : 'approved',
        'disputed_at' => $disputed ? now() : null,
    ]);

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2,
        'individual_status' => $autoApproved ? 'auto_approved' : 'approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(),
        'auto_approved' => $autoApproved,
        'review_due_at' => $autoApproved ? now()->addHours(6) : null,
        'admin_reviewed_at' => $reviewed ? now() : null,
    ]);

    return $document;
}

test('an unreviewed auto-approved document counts as In Progress, not Approved', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    bucketingDoc($originator, autoApproved: true, reviewed: false);

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');

    expect($stats['pending'])->toBe(1)
        ->and($stats['approved'])->toBe(0);
});

test('a reviewed auto-approved document counts as Approved, not In Progress', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    bucketingDoc($originator, autoApproved: true, reviewed: true);

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');

    expect($stats['pending'])->toBe(0)
        ->and($stats['approved'])->toBe(1);
});

test('a real human-approved document still counts as Approved', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    bucketingDoc($originator, autoApproved: false);

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');

    expect($stats['approved'])->toBe(1)
        ->and($stats['pending'])->toBe(0);
});

test('a disputed auto-approved document counts as Disputed, not In Progress or Approved', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    bucketingDoc($originator, autoApproved: true, reviewed: true, disputed: true);

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');

    expect($stats['pending'])->toBe(0)
        ->and($stats['approved'])->toBe(0)
        ->and($stats['disputed'])->toBe(1);
});

test('a disputed auto-approved document the Admin never reviewed is still In Progress, not Disputed yet', function () {
    // disputed_at is only ever set together with admin_reviewed_at by
    // reviewAutoApproval() — this covers the shape the code never
    // actually produces (disputed_at set, admin_reviewed_at still null),
    // so "In Progress" and "Disputed" never silently double-count the
    // same document between them regardless of how disputed_at got set.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    bucketingDoc($originator, autoApproved: true, reviewed: false, disputed: true);

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');

    expect($stats['pending'])->toBe(1)
        ->and($stats['disputed'])->toBe(1);
});

test('the Approved and In Progress drilldown lists agree with the KPI counts', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    // Deliberately non-overlapping titles — "unreviewed.txt" contains
    // "reviewed.txt" as a substring, which would make assertDontSee()
    // fail on text that's genuinely absent from the page.
    $unreviewed = bucketingDoc($originator, autoApproved: true, reviewed: false, title: 'pending-doc.txt');
    $reviewed = bucketingDoc($originator, autoApproved: true, reviewed: true, title: 'settled-doc.txt');

    $approvedList = $this->actingAs($admin)->get(route('admin.dashboard.drilldown', 'approved'));
    $pendingList = $this->actingAs($admin)->get(route('admin.dashboard.drilldown', 'pending'));

    $approvedList->assertOk()->assertSee($reviewed->title)->assertDontSee($unreviewed->title);
    $pendingList->assertOk()->assertSee($unreviewed->title)->assertDontSee($reviewed->title);
});

test('the Disputed drilldown list agrees with its KPI count and excludes it from In Progress/Approved', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $disputed = bucketingDoc($originator, autoApproved: true, reviewed: true, disputed: true, title: 'disputed-doc.txt');
    $settled = bucketingDoc($originator, autoApproved: true, reviewed: true, title: 'settled-doc.txt');

    $stats = $this->actingAs($admin)->getJson(route('admin.dashboard.poll'))->json('stats');
    $disputedList = $this->actingAs($admin)->get(route('admin.dashboard.drilldown', 'disputed'));

    expect($stats['disputed'])->toBe(1);
    $disputedList->assertOk()->assertSee($disputed->title)->assertDontSee($settled->title);
});
