<?php

use App\Models\DocumentRepository;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Covers the cleanup command for the pre-fix double-click upload bug (see
 * PurgeDuplicateDocumentUploads's own docblock) — run once against
 * production's existing backlog, never scheduled.
 */
function duplicateGuardDoc(User $originator, array $overrides = []): DocumentRepository
{
    $path = $overrides['file_path'] ?? 'documents/'.uniqid().'.txt';
    Storage::put($path, $overrides['content'] ?? str_repeat('a', 100));

    $document = DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => $overrides['original_filename'] ?? 'memo.txt',
        'file_path' => $path,
        'original_filename' => $overrides['original_filename'] ?? 'memo.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => now()->addDay(),
        'global_status' => 'auto_approved',
    ], array_diff_key($overrides, array_flip(['content']))));

    if (isset($overrides['upload_date'])) {
        DocumentRepository::where('document_id', $document->document_id)->update(['upload_date' => $overrides['upload_date']]);
        $document->refresh();
    }

    return $document;
}

it('deletes the later of two same-size, same-name, back-to-back uploads, keeping the earliest', function () {
    Storage::fake();
    $originator = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(100)]);
    $second = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(97)]); // 3s after $first

    $this->artisan('documents:purge-duplicate-uploads', ['--force' => true])
        ->expectsOutputToContain('1 duplicate document(s) found')
        ->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->toBeNull()
        ->and(Storage::exists($second->file_path))->toBeFalse();
});

it('chains three identical back-to-back uploads down to the earliest one', function () {
    Storage::fake();
    $originator = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(100)]);
    $second = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(97)]);
    $third = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(94)]);

    $this->artisan('documents:purge-duplicate-uploads', ['--force' => true])->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->toBeNull()
        ->and(DocumentRepository::find($third->document_id))->toBeNull();
});

it('leaves two same-name uploads alone when they are far apart in time', function () {
    Storage::fake();
    $originator = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originator, ['upload_date' => now()->subDays(7)]);
    $second = duplicateGuardDoc($originator, ['upload_date' => now()]);

    $this->artisan('documents:purge-duplicate-uploads', ['--force' => true])
        ->expectsOutputToContain('No duplicate uploads found')
        ->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->not->toBeNull();
});

it('leaves two same-name, same-time uploads alone when their file sizes differ', function () {
    Storage::fake();
    $originator = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originator, ['content' => str_repeat('a', 100), 'upload_date' => now()->subSeconds(100)]);
    $second = duplicateGuardDoc($originator, ['content' => str_repeat('a', 250), 'upload_date' => now()->subSeconds(97)]);

    $this->artisan('documents:purge-duplicate-uploads', ['--force' => true])
        ->expectsOutputToContain('No duplicate uploads found')
        ->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->not->toBeNull();
});

it('does not cross-match two different originators who happened to upload the same filename', function () {
    Storage::fake();
    $originatorA = User::factory()->originator()->create();
    $originatorB = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originatorA, ['upload_date' => now()->subSeconds(100)]);
    $second = duplicateGuardDoc($originatorB, ['upload_date' => now()->subSeconds(97)]);

    $this->artisan('documents:purge-duplicate-uploads', ['--force' => true])
        ->expectsOutputToContain('No duplicate uploads found')
        ->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->not->toBeNull();
});

it('without --force, asks for confirmation and deletes nothing on a declined prompt', function () {
    Storage::fake();
    $originator = User::factory()->originator()->create();

    $first = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(100)]);
    $second = duplicateGuardDoc($originator, ['upload_date' => now()->subSeconds(97)]);

    $this->artisan('documents:purge-duplicate-uploads')
        ->expectsConfirmation('Delete these 1 duplicate document(s) and their stored files? This also removes their assignments, violations, and audit history.', 'no')
        ->expectsOutputToContain('Nothing deleted')
        ->assertSuccessful();

    expect(DocumentRepository::find($first->document_id))->not->toBeNull()
        ->and(DocumentRepository::find($second->document_id))->not->toBeNull();
});
