<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;

/**
 * Feature: a disputed auto-approval can be resubmitted, same as a rejected
 * document — see DocumentRepository::isResubmittable()'s docblock. Before
 * this, a document Admin disputed had no way out: resubmit() only ever
 * accepted 'rejected'/'processing', and 'auto_approved' + disputed_at was
 * neither of those.
 */
beforeEach(fn () => Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')));

function disputedDocument(User $originator, ?string $note = 'The amount field is wrong.'): DocumentRepository
{
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'disputed.txt',
        'file_path' => 'documents/disputed.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'auto_approved',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'disputed_at' => now(),
    ]);

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 1, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true,
        'review_due_at' => now()->addHours(6), 'admin_reviewed_at' => now(),
        'admin_review_outcome' => 'disputed', 'admin_review_note' => $note,
    ]);

    return $document;
}

test('a disputed auto-approved document can be resubmitted, same as a rejected one', function () {
    $originator = User::factory()->originator()->create();
    $disputed = disputedDocument($originator);

    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $disputed), [
        'file' => UploadedFile::fake()->createWithContent('fixed.txt',
            "Date Requested: July 16, 2026\nRequested By: Test Requester\nDescription of Work: "
            .str_repeat('corrected amount on the purchase form ', 5)),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
    ]);

    $response->assertRedirect();
    $newVersion = $disputed->fresh()->nextVersion;
    expect($newVersion)->not->toBeNull()
        ->and($newVersion->previous_version_id)->toBe($disputed->document_id)
        // The old document keeps its own history untouched — still the
        // permanent record of the auto-approval and the dispute, not
        // reset just because a new version exists.
        ->and($disputed->fresh()->global_status)->toBe('auto_approved')
        ->and($disputed->fresh()->disputed_at)->not->toBeNull();
});

test('an auto-approved document Admin has not reviewed yet still cannot be resubmitted', function () {
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);

    $unreviewed = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'unreviewed.txt', 'file_path' => 'documents/unreviewed.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'auto_approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $unreviewed->document_id, 'user_id' => null, 'stage_id' => $stage->stage_id,
        'due_date' => $unreviewed->due_date, 'priority_rank' => 1, 'individual_status' => 'auto_approved',
        'sla_expires_at' => now()->subHour(), 'acted_at' => now(), 'auto_approved' => true,
        'review_due_at' => now()->addHours(6),
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $unreviewed), [
        'file' => UploadedFile::fake()->createWithContent('x.txt', 'irrelevant'),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
    ]);

    $response->assertStatus(409);
});

test('the tracker shows the Admin\'s dispute note and a resubmit option for a disputed document', function () {
    $originator = User::factory()->originator()->create();
    $disputed = disputedDocument($originator, note: 'The amount field is wrong.');

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $disputed));

    $response->assertOk()
        ->assertSee('an Admin disputed it')
        ->assertSee('The amount field is wrong.', false)
        ->assertSee('Resubmit a revised version');
});
