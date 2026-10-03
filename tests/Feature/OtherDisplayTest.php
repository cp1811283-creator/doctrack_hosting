<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Services\ClassificationService;
use Illuminate\Http\UploadedFile;

function unrelatedDisplayDoc(User $originator, bool $pending = false): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'weird-memo.txt', 'file_path' => 'documents/weird.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'ml_confidence' => 42, 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'unrelated',
        'pending_custom_routing_at' => $pending ? now() : null,
        'custom_routed' => ! $pending,
    ]);
}

test('display_category shows Other for a document flagged unrelated, hiding the classifier guess', function () {
    $originator = User::factory()->originator()->create();
    $unrelated = unrelatedDisplayDoc($originator);

    $normal = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'real.txt', 'file_path' => 'documents/real.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'auto',
    ]);

    expect($unrelated->display_category)->toBe('Other')
        ->and($unrelated->ml_category)->toBe('Job Order') // still stored underneath, just not displayed
        ->and($normal->display_category)->toBe('Job Order');
});

test('the submissions table shows Other, not the raw guess, for an unrelated document', function () {
    $originator = User::factory()->originator()->create();
    unrelatedDisplayDoc($originator, pending: true);

    $response = $this->actingAs($originator)->get(route('originator.dashboard'));

    // Not assertDontSee('Job Order') here — the page's own "All
    // Categories" filter dropdown legitimately lists every real category
    // by name regardless of this document, so that string appearing
    // somewhere on the page isn't itself meaningful; what matters is
    // that THIS document's own row shows Other.
    $response->assertOk()->assertSee('Other');
});

test('the tracking page shows Other as the category, plus the classifier\'s non-authoritative best guess, for an unrelated document', function () {
    // Classification, readability and validation genuinely run on every
    // document, "unrelated" ones included (see WorkflowService::ingest())
    // — the tracking page now shows that instead of hiding it, worded as
    // a "best guess" (not a bare "Confidence:") so it doesn't read as
    // contradicting the "Other" category shown alongside it.
    $originator = User::factory()->originator()->create();
    $document = unrelatedDisplayDoc($originator, pending: true);

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()
        ->assertSee('Other')
        ->assertSee('Job Order')
        ->assertSee('best guess')
        ->assertDontSee('Confidence:');
});

test('the upload confirmation message says "marked as Other" for an unrelated upload, not "classified as Job Order"', function () {
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 42, 'margin' => 5.0, 'model_id' => null]);
    // WorkflowService::ingest() dispatches CheckAutoTrainDue after every
    // successful ingest (QUEUE_CONNECTION=sync in tests runs it inline,
    // against this same mocked instance) — irrelevant to what this test
    // actually checks, but a strict mock throws on any unstubbed call.
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);

    // Pinned rather than now()->addHours(4) — that was flaky (real-clock
    // dependent: whatever hour the suite actually happens to run at,
    // +4 hours can easily land outside the 9 AM-5 PM business-hours
    // window, which the due_date validator correctly rejects, making
    // this test fail depending purely on what time it's run — confirmed
    // by reproducing it directly: due_date validation failed exactly
    // this way against the real host clock).
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'), // comfortably within the same business day
        'routing_mode' => 'unrelated',
    ]);

    $response->assertRedirect(route('originator.dashboard'));
    $response->assertSessionHas('status', function ($status) {
        return str_contains($status, 'marked as Other') && ! str_contains($status, "classified as 'Job Order'");
    });
});
