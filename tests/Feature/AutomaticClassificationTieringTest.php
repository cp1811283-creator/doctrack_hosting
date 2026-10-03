<?php

use App\Models\DocumentAssignment;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

// Pinned for the whole file — several tests compute due_date via
// now()->addDay(), which is flaky against the real host clock (can land
// outside the 9 AM-5 PM business-hours window depending purely on what
// time the suite happens to run — confirmed reproducing this exact
// failure mode once already this session).
beforeEach(fn () => Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')));

/**
 * Coverage for WorkflowService::ingest()'s automatic tiering (Feature: no
 * manual admin review anywhere in this decision) — a document is trusted
 * and routed automatically as long as the classifier's confidence beats
 * the random-chance floor for however many categories are trained (1/N —
 * with the 3 categories seeded below, ~33.33%). Margin and readability are
 * recorded but never block routing — see WorkflowService::ingest()'s
 * $belowChanceFloor docblock and ValidationService::validate()'s.
 */
function tieringDoc(float $confidence, float $margin, string $content): array
{
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Only Stage', 'sequence_order' => 1]);
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => $confidence, 'margin' => $margin, 'model_id' => null]);
    // See the matching comment in OtherDisplayTest.php — WorkflowService::
    // ingest() dispatches CheckAutoTrainDue, which runs inline under the
    // test suite's sync queue connection against this same strict mock.
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    $document = app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('memo.txt', $content),
        $originator,
        now()->addDay()->toDateTimeString(),
    );

    return compact('document', 'approver', 'originator');
}

// Matches the Job Order template's required_sections + min_word_count —
// validation itself isn't under test here, so this stays constant across
// cases and is deliberately content that WOULD pass, so a rejection in
// any of these tests is unambiguously the tiering logic, not validation.
function tieringContent(): string
{
    return "Job Order No: JO-1\nDate Requested: today\nRequested By: someone\nDescription of Work: "
        .str_repeat('fix the widget assembly line carefully and thoroughly ', 5);
}

test('high confidence routes automatically and adds nothing ambiguous to the record, regardless of margin', function () {
    ['document' => $document] = tieringDoc(confidence: 90.0, margin: 3.0, content: tieringContent());

    expect($document->global_status)->toBe('classified_validated')
        ->and($document->ml_margin)->toBe(3.0)
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

test('confidence just above the random-chance floor still routes automatically, even with a razor-thin margin', function () {
    // 3 trained categories (Job Order, Purchase Requisition, Service
    // Report) => chance floor is 100/3 ≈ 33.33%. 35% clears it despite an
    // almost-flat margin — margin no longer gates anything, only
    // confidence-vs-chance-floor does. This is exactly the case that used
    // to get rejected as "ambiguous" purely for having a thin lead, even
    // though the guess itself was perfectly informative.
    ['document' => $document] = tieringDoc(confidence: 35.0, margin: 2.0, content: tieringContent());

    expect($document->global_status)->toBe('classified_validated')
        ->and($document->ml_margin)->toBe(2.0)
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

test('confidence below the random-chance floor is rejected, not routed', function () {
    // 20% is worse than guessing among 3 categories (~33.33%) — the model
    // genuinely has no real opinion here, regardless of margin.
    ['document' => $document, 'originator' => $originator] = tieringDoc(confidence: 20.0, margin: 5.0, content: tieringContent());

    expect($document->global_status)->toBe('rejected')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0)
        ->and(NotificationRecord::where('recipient_id', $originator->user_id)
            ->where('document_id', $document->document_id)
            ->where('message_body', 'like', "%doesn't clearly match any of our trained categories%")
            ->exists())->toBeTrue();
});

test('a document rejected for being below the chance floor can be resubmitted with an explicit routing_mode choice', function () {
    ['document' => $document, 'originator' => $originator] = tieringDoc(confidence: 20.0, margin: 5.0, content: tieringContent());
    expect($document->global_status)->toBe('rejected');

    // 'unrelated' specifically — since the classifier is still mocked to
    // the same low-confidence result, this proves the originator's own
    // routing_mode choice is what gets them past the chance-floor check
    // this time ('unrelated' is exempt from it entirely — see ingest()'s
    // $belowChanceFloor docblock), not a lucky re-roll of the classifier's
    // guess.
    $response = $this->actingAs($originator)->post(route('originator.documents.resubmit', $document), [
        'file' => UploadedFile::fake()->createWithContent('revised.txt', tieringContent()),
        'due_date' => now()->addDay()->format('Y-m-d\TH:i'),
        'routing_mode' => 'unrelated',
    ]);

    $response->assertRedirect();
    $newDocument = $document->fresh()->nextVersion;
    expect($newDocument)->not->toBeNull()
        ->and($newDocument->desired_routing)->toBe('unrelated')
        ->and($newDocument->global_status)->not->toBe('rejected')
        ->and($newDocument->pending_custom_routing_at)->not->toBeNull();
});
