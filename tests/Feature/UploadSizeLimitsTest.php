<?php

use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for a real confirmed gap: the previous numbers
 * (20MB per file, up to 20 files) had no real relationship to the actual
 * total ceiling the server would accept — 20 x 20 = 400MB, a batch that
 * could pass both those rules individually and still get rejected one
 * layer down (PHP's post_max_size), with no app-level rule explaining
 * why. These numbers are deliberately reconciled instead: 15MB x 10
 * files = 150MB exactly, so passing the per-file and file-count rules
 * now GUARANTEES the total is within the real ceiling — see
 * DocumentController::store()'s own docblock for the full reasoning.
 */
function withinBusinessHoursDueDate(): array
{
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    return ['due_date' => now()->addHours(4)->format('Y-m-d\TH:i')];
}

it('rejects more than 10 files in a single batch', function () {
    $originator = User::factory()->originator()->create();

    $files = collect(range(1, 11))->map(fn ($i) => UploadedFile::fake()->create("doc-{$i}.txt", 1))->all();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => $files,
        ...withinBusinessHoursDueDate(),
    ]);

    $response->assertSessionHasErrors('files');
});

it('rejects a single file over 15MB', function () {
    $originator = User::factory()->originator()->create();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->create('too-big.pdf', 15361)], // 1 KB over 15MB
        ...withinBusinessHoursDueDate(),
    ]);

    $response->assertSessionHasErrors('files.0');
});

/**
 * The real point of this feature: 10 files at exactly 15MB each (the
 * full, legitimate worst case these rules allow) totals exactly 150MB —
 * this must be ACCEPTED, not spuriously rejected by the separate
 * total-batch-size rule. Proves the reconciled math actually holds
 * rather than just being asserted in a comment. Note: because 10 x 15MB
 * can never mathematically exceed 150MB, the total-size rule's own
 * rejection branch is unreachable through this endpoint as long as the
 * per-file/file-count numbers stay as they are — it exists as a
 * deliberate safety net against those numbers drifting apart in the
 * future, not something a legitimate request can trigger today.
 */
it('accepts the full legitimate worst case — 10 files at exactly 15MB each', function () {
    $originator = User::factory()->originator()->create();
    User::factory()->approver('Job Order')->create();
    WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Technical Review'],
        ['sequence_order' => 1]
    );

    $files = collect(range(1, 10))->map(fn ($i) => UploadedFile::fake()->create("doc-{$i}.pdf", 15360))->all();

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => $files,
        ...withinBusinessHoursDueDate(),
    ]);

    $response->assertSessionDoesntHaveErrors('files');
});

it('rejects a legacy import file over 15MB', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.archive.legacy'), [
        'file' => UploadedFile::fake()->create('too-big.pdf', 15361),
        'category' => 'Job Order',
        'reason' => 'Digitizing old paperwork.',
    ]);

    $response->assertSessionHasErrors('file');
});

it('rejects more than 10 files via the API upload endpoint too', function () {
    $originator = User::factory()->originator()->create();

    $files = collect(range(1, 11))->map(fn ($i) => UploadedFile::fake()->create("doc-{$i}.txt", 1))->all();

    $response = $this->actingAs($originator, 'sanctum')->postJson(route('api.documents.store'), [
        'files' => $files,
        ...withinBusinessHoursDueDate(),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('files');
});
