<?php

use App\Events\DocumentStatusChanged;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

/**
 * A document with 2 auto-approved stages (e.g. Budget Check AND Final
 * Approval both firing) — reviewAutoApproval() acts on the WHOLE document
 * at once, not stage-by-stage, per the admin's explicit request that they
 * review a document holistically rather than clicking through each stage.
 */
function documentWithAutoApprovedStages(int $count = 2): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'auto-approved.txt',
        'file_path' => 'documents/auto-approved.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'auto_approved',
        'ml_category' => 'Job Order',
    ]);

    for ($i = 1; $i <= $count; $i++) {
        $approver = User::factory()->approver('Job Order')->create();
        $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => "Stage {$i}", 'sequence_order' => $i]);

        DocumentAssignment::create([
            'document_id' => $document->document_id,
            'user_id' => $approver->user_id,
            'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date,
            'priority_rank' => 2,
            'individual_status' => 'approved',
            'auto_approved' => true,
            'acted_at' => now()->subHour(),
            'sla_expires_at' => now()->subHours(13),
        ]);
    }

    return $document;
}

test('confirming a document marks every auto-approved stage reviewed at once', function () {
    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(2);

    $response = $this->actingAs($admin)->post(route('admin.sla.review', $document), ['outcome' => 'confirmed']);

    $response->assertRedirect();
    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();
    expect($assignments)->toHaveCount(2)
        ->and($assignments->every(fn ($a) => $a->admin_review_outcome === 'confirmed'))->toBeTrue()
        ->and($assignments->every(fn ($a) => $a->admin_reviewed_by === $admin->user_id))->toBeTrue()
        ->and($document->fresh()->disputed_at)->toBeNull()
        ->and($document->fresh()->global_status)->toBe('auto_approved');
});

test('disputing a document flags every auto-approved stage and sets disputed_at once, without reversing the approval', function () {
    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(2);

    $response = $this->actingAs($admin)->post(route('admin.sla.review', $document), [
        'outcome' => 'disputed',
        'note' => 'Wrong department billed.',
    ]);

    $response->assertRedirect();
    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();
    $fresh = $document->fresh();

    expect($assignments->every(fn ($a) => $a->admin_review_outcome === 'disputed'))->toBeTrue()
        ->and($assignments->every(fn ($a) => $a->admin_review_note === 'Wrong department billed.'))->toBeTrue()
        ->and($fresh->disputed_at)->not->toBeNull()
        ->and($fresh->global_status)->toBe('auto_approved') // never reversed
        ->and($fresh->display_status)->toBe('disputed')
        ->and(NotificationRecord::where('recipient_id', $document->originator_id)->where('message_body', 'like', '%disputed%')->exists())->toBeTrue();
});

test('disputing without a note is rejected', function () {
    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(1);

    $this->actingAs($admin)
        ->post(route('admin.sla.review', $document), ['outcome' => 'disputed'])
        ->assertSessionHasErrors('note');
});

test('reviewing a document with nothing awaiting review 404s', function () {
    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(1);
    DocumentAssignment::where('document_id', $document->document_id)->update(['admin_reviewed_at' => now(), 'admin_review_outcome' => 'confirmed']);

    $this->actingAs($admin)
        ->post(route('admin.sla.review', $document), ['outcome' => 'confirmed'])
        ->assertNotFound();
});

test('the approver roster includes approvers with zero breaches, ranked by breach count', function () {
    $admin = User::factory()->admin()->create();
    $offender = User::factory()->approver('Job Order')->create(['full_name' => 'Offender Approver']);
    $clean = User::factory()->approver('Job Order')->create(['full_name' => 'Clean Approver']);

    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'roster-test.txt',
        'file_path' => 'documents/roster-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $offender->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'pending',
        'sla_expires_at' => now()->subMinutes(5),
    ]);
    SlaViolation::create([
        'document_id' => $assignment->document_id,
        'assignment_id' => $assignment->assignment_id,
        'approver_id' => $offender->user_id,
        'violation_timestamp' => now(),
        'duration_overdue' => 30,
        'stage_name' => 'Technical Review',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));

    $response->assertOk();
    $roster = $response->viewData('approverRoster');

    expect($roster->pluck('full_name')->all())->toContain('Offender Approver', 'Clean Approver');
    $offenderRow = $roster->firstWhere('full_name', 'Offender Approver');
    $cleanRow = $roster->firstWhere('full_name', 'Clean Approver');
    expect($offenderRow->violation_count)->toBe(1)
        ->and($cleanRow->violation_count)->toBe(0)
        ->and($roster->search(fn ($a) => $a->full_name === 'Offender Approver'))
        ->toBeLessThan($roster->search(fn ($a) => $a->full_name === 'Clean Approver'));
});

test('disputing broadcasts DocumentStatusChanged so the originator sees it instantly, not just on the next poll', function () {
    Event::fake([DocumentStatusChanged::class]);

    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(1);

    $this->actingAs($admin)->post(route('admin.sla.review', $document), [
        'outcome' => 'disputed',
        'note' => 'Needs a second look.',
    ]);

    Event::assertDispatched(DocumentStatusChanged::class, fn ($event) => $event->document->document_id === $document->document_id);
});

test('confirming DOES broadcast DocumentStatusChanged, so an already-open page sees display_status flip to Approved without a manual reload', function () {
    // Reversed 2026-10-03 — this test previously asserted the opposite and
    // was itself the bug: confirming only ever touched admin_reviewed_at on
    // the assignment rows, never global_status/disputed_at on the document
    // itself (the only two columns DocumentRepository::booted() watches),
    // so nothing ever told an already-open originator/admin page to
    // re-fetch. DocumentRepository::display_status already correctly
    // computes 'approved' the instant every auto-approved stage is
    // reviewed — the status WAS right on a fresh page load, just invisible
    // without one. See AdminController::reviewAutoApproval()'s confirmed
    // branch.
    Event::fake([DocumentStatusChanged::class]);

    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(1);

    $this->actingAs($admin)->post(route('admin.sla.review', $document), ['outcome' => 'confirmed']);

    Event::assertDispatched(DocumentStatusChanged::class, fn ($event) => $event->document->document_id === $document->document_id);
});

test('confirming every auto-approved stage flips display_status to approved', function () {
    $admin = User::factory()->admin()->create();
    $document = documentWithAutoApprovedStages(1);
    // review_due_at is what makes display_status treat a stage as still
    // genuinely AWAITING review (see its own accessor) — the shared
    // documentWithAutoApprovedStages() helper leaves it null, which
    // already reads as "approved" with nothing to confirm; this test is
    // specifically about the still-pending case.
    DocumentAssignment::where('document_id', $document->document_id)->update(['review_due_at' => now()->addHours(6)]);

    expect($document->fresh()->display_status)->toBe('auto_approved');

    $this->actingAs($admin)->post(route('admin.sla.review', $document), ['outcome' => 'confirmed']);

    expect($document->fresh()->display_status)->toBe('approved');
});
