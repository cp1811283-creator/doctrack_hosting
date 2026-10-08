<?php

use App\Models\DocumentRepository;
use App\Models\User;

/**
 * Regression coverage for a real reported bug: the resubmit form's own
 * visibility gate (DocumentRepository::isResubmittable()) only checks the
 * DOCUMENT's state, never who's viewing it. Admin can view the same
 * tracker page (DocumentRepositoryPolicy::viewTracking() allows it), but
 * resubmitting is owner-only (DocumentRepositoryPolicy::resubmit()) — so
 * Admin used to see a working-looking form that would be rejected the
 * moment they actually submitted it.
 */
function stuckDocumentFor(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'stuck.txt', 'file_path' => 'documents/stuck.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'rejected', 'ml_category' => 'Job Order',
    ]);
}

it('shows the resubmit form to the document\'s own originator', function () {
    $originator = User::factory()->originator()->create();
    $document = stuckDocumentFor($originator);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()->assertSee('Resubmit a revised version');
});

it('does not show the resubmit form to Admin viewing someone else\'s document', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = stuckDocumentFor($originator);

    $response = $this->actingAs($admin)->get(route('documents.track', $document));

    $response->assertOk()->assertDontSee('Resubmit a revised version');
});
