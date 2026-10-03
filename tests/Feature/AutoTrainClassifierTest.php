<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\MlModelRepository;
use App\Models\MlStagingSample;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;

/**
 * Coverage for ClassificationService::autoTrainIfDue() — the fully-
 * automatic retraining that replaces the manual "Train Model" button for
 * every retrain after the one-time bootstrap. See that method's own
 * docblock for the two triggers (batch size / age) and the accuracy-
 * gated rollback.
 */
/**
 * Needs REAL lexical diversity between calls, not just a different number
 * in an otherwise-fixed template — ClassificationService::autoTrainIfDue()
 * now runs real near-duplicate detection (config('ml.near_duplicate_threshold'),
 * 85% word overlap) against every eligible document, and two calls that
 * only differed by one number were themselves ~91% word-overlap — exactly
 * what that filter is supposed to catch, which broke every test here that
 * creates several documents meant to represent genuinely different
 * real-world content. A distinct word pool per $n keeps these fixtures
 * below that threshold the same way two real, different documents would
 * naturally be.
 */
function distinctText(string $keyword, int $n): string
{
    // LETTERS only, not digits — ClassificationService::preprocess() (what
    // wordOverlapSimilarity() actually compares) strips every non-alphabetic
    // character before comparing, so digits contribute nothing to
    // distinguishing two texts no matter how different the numbers look —
    // {$n} alone was never enough, and neither was an earlier attempt at a
    // numeric filler. Random lowercase "words" are what actually survives
    // preprocessing and keeps two calls genuinely below the near-duplicate
    // threshold (config('ml.near_duplicate_threshold')), the same way two
    // real, different documents' wording would.
    $filler = collect(range(0, 9))
        ->map(fn () => collect(range(0, 5))->map(fn () => chr(97 + random_int(0, 25)))->implode(''))
        ->implode(' ');

    return "{$keyword} reference number {$n}. This document concerns {$keyword} matters exclusively, "
        ."filed on a different date with different details each time, sample variant {$n}. "
        ."Unique context tokens: {$filler}.";
}

/** Stages real, distinctly-worded curated samples for all 3 categories and trains a real bootstrap model. */
function bootstrapModel(int $perCategory = 5): MlModelRepository
{
    $keywords = [
        'Job Order' => 'aircon repair plumbing maintenance facilities work order',
        'Purchase Requisition' => 'laptop budget procurement requisition finance approval',
        'Service Report' => 'technician generator inspection findings completed service',
    ];

    foreach ($keywords as $category => $keyword) {
        for ($i = 1; $i <= $perCategory; $i++) {
            MlStagingSample::create([
                'category' => $category,
                'original_filename' => "seed-{$category}-{$i}.txt",
                'extracted_text' => distinctText($keyword, $i),
            ]);
        }
    }

    $samplesByCategory = MlStagingSample::get()->groupBy('category')
        ->map(fn ($rows) => $rows->pluck('extracted_text')->all())->all();

    return app(ClassificationService::class)->train($samplesByCategory);
}

/**
 * A confidently-classified, auto-trusted document eligible to teach the
 * model — matches WorkflowService::ingest()'s trusted tier. Also creates
 * the DocumentAssignment a real routed document would have —
 * autoTrainIfDue()'s eligibility query requires one (whereHas('assignments'))
 * specifically to exclude documents rejected below the confidence floor,
 * which never get routed at all; without a matching assignment row here,
 * these otherwise-legitimate test fixtures would be excluded the same way.
 */
function eligibleDoc(string $category, array $overrides = []): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $doc = DocumentRepository::create(array_merge([
        'originator_id' => $originator->user_id,
        'title' => 'eligible-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'ocr_text' => distinctText($category, random_int(1000, 9999)),
        'ml_category' => $category,
        // Below the default auto_train_confidence_ceiling (75) — most of
        // this file's tests aren't about confidence at all and expect a
        // plain, ordinary document to be training-eligible by default; a
        // dedicated confidence-ceiling test overrides this explicitly.
        'ml_confidence' => 60.0,
        'ml_margin' => 50.0,
        'is_validated' => true,
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
    ], $overrides));

    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );

    DocumentAssignment::create([
        'document_id' => $doc->document_id,
        'user_id' => null,
        'stage_id' => $stage->stage_id,
        'due_date' => $doc->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'acted_at' => now(),
        'auto_approved' => true,
    ]);

    return $doc;
}

test('returns null when no model has ever been bootstrapped', function () {
    eligibleDoc('Job Order');

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
});

test('returns null when eligible documents exist but neither trigger is met', function () {
    bootstrapModel();
    // 2 waiting — below the default batch size (5) — and just trained, so
    // the age trigger (24h) hasn't elapsed either.
    eligibleDoc('Job Order');
    eligibleDoc('Job Order');

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect(DocumentRepository::whereNotNull('used_for_training_at')->count())->toBe(0);
});

test('fires once the batch-size trigger is met', function () {
    config(['ml.auto_train_batch_size' => 3]);
    $original = bootstrapModel();

    $docs = collect([eligibleDoc('Job Order'), eligibleDoc('Job Order'), eligibleDoc('Job Order')]);

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(3);

    $docs->each(fn ($d) => expect($d->fresh()->used_for_training_at)->not->toBeNull());

    // A genuinely new model version was created (whether kept or rolled
    // back — see the rollback test below for that distinction).
    expect(MlModelRepository::count())->toBeGreaterThan(1)
        ->and(MlModelRepository::find($original->model_id))->not->toBeNull();
});

test('fires once the age trigger is met, even with only 1 document waiting', function () {
    config(['ml.auto_train_max_age_hours' => 24]);
    $original = bootstrapModel();

    // Backdate the bootstrap model past the age threshold.
    $original->update(['last_trained' => now()->subHours(25)]);

    eligibleDoc('Job Order');

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(1);
});

test('excludes documents the originator flagged unrelated, even at high confidence', function () {
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    eligibleDoc('Job Order', ['desired_routing' => 'unrelated']);

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
});

test('includes low-confidence documents in training too, and re-scores them once the retrain is kept', function () {
    // No confidence/margin floor on training eligibility anymore — a
    // document this unsure is exactly the kind that needs its real,
    // unfamiliar vocabulary folded into the next model. See
    // autoTrainIfDue()'s docblock on the eligibility query.
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    $doc = eligibleDoc('Job Order', ['ml_confidence' => 40.0, 'ml_margin' => 5.0]);

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(1);

    $doc->refresh();
    expect($doc->ml_rechecked_at)->not->toBeNull()
        ->and($doc->ml_recheck_confidence)->not->toBeNull()
        ->and($doc->ml_recheck_category)->not->toBeNull()
        // Readability gets the identical recheck treatment, same batch,
        // same trigger — see ValidationService::categoryVocabulary()'s
        // widened source and autoTrainIfDue()'s recheck step.
        ->and($doc->ml_recheck_readability_score)->not->toBeNull();
});

test('excludes a document at or above the confidence ceiling from the training queue', function () {
    // "Uncertainty sampling" — a document the classifier is already
    // confident about teaches the model nothing new, so it's excluded from
    // ever counting toward either trigger or being folded into the corpus.
    // See config('ml.auto_train_confidence_ceiling').
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_confidence_ceiling' => 75]);
    bootstrapModel();

    eligibleDoc('Job Order', ['ml_confidence' => 75.0]); // AT the ceiling — excluded
    eligibleDoc('Job Order', ['ml_confidence' => 90.0]); // above — excluded

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect(DocumentRepository::whereNotNull('used_for_training_at')->count())->toBe(0);
});

test('includes a document below the confidence ceiling but above the reject floor', function () {
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_confidence_ceiling' => 75]);
    bootstrapModel();

    $doc = eligibleDoc('Job Order', ['ml_confidence' => 74.9]);

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull();
    expect($doc->fresh()->used_for_training_at)->not->toBeNull();
});

test('excludes an exact duplicate of another document in the same waiting batch, so it never inflates the trigger count', function () {
    // Regression coverage for a real reported bug (confirmed 2026-10-03):
    // uploading the same file twice both landed in the training queue,
    // since nothing compared them to each other — see
    // excludeNearDuplicates(). Batch size is 2, but only 1 of these 2
    // uploads is genuinely unique, so this must NOT fire — if it did, a
    // company uploading the same document 5 times could falsely trigger a
    // retrain that only actually taught the model 1 real thing.
    config(['ml.auto_train_batch_size' => 2]);
    bootstrapModel();

    $sharedText = distinctText('Job Order', 777);
    $first = eligibleDoc('Job Order', ['ocr_text' => $sharedText]);
    $second = eligibleDoc('Job Order', ['ocr_text' => $sharedText]);

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect($first->fresh()->used_for_training_at)->toBeNull()
        ->and($second->fresh()->used_for_training_at)->toBeNull();
});

test('still fires once enough genuinely distinct documents exist, excluding only the duplicate among them', function () {
    config(['ml.auto_train_batch_size' => 2]);
    bootstrapModel();

    $sharedText = distinctText('Job Order', 777);
    $first = eligibleDoc('Job Order', ['ocr_text' => $sharedText]);
    $duplicate = eligibleDoc('Job Order', ['ocr_text' => $sharedText]);
    $distinct = eligibleDoc('Job Order');

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull();
    expect($first->fresh()->used_for_training_at)->not->toBeNull()
        ->and($distinct->fresh()->used_for_training_at)->not->toBeNull()
        ->and($duplicate->fresh()->used_for_training_at)->toBeNull();
});

test('excludes a near-duplicate of a real document already used in a past retrain', function () {
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    $original = eligibleDoc('Job Order');
    app(ClassificationService::class)->autoTrainIfDue();
    expect($original->fresh()->used_for_training_at)->not->toBeNull();

    $nearDuplicate = eligibleDoc('Job Order', ['ocr_text' => $original->fresh()->ocr_text]);

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect($nearDuplicate->fresh()->used_for_training_at)->toBeNull();
});

test('excludes a near-duplicate of a curated sample', function () {
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    $curatedSample = MlStagingSample::where('category', 'Job Order')->first();
    $nearDuplicate = eligibleDoc('Job Order', ['ocr_text' => $curatedSample->extracted_text]);

    expect(app(ClassificationService::class)->autoTrainIfDue())->toBeNull();
    expect($nearDuplicate->fresh()->used_for_training_at)->toBeNull();
});

test('does not flag two genuinely different documents sharing normal category boilerplate as duplicates', function () {
    // The false-positive guard the 85% threshold exists for — real,
    // distinct same-category documents naturally share up to ~80% of
    // their vocabulary from boilerplate alone (see config('ml.
    // near_duplicate_threshold')'s docblock); these must still both count.
    config(['ml.auto_train_batch_size' => 2]);
    bootstrapModel();

    $a = eligibleDoc('Job Order');
    $b = eligibleDoc('Job Order');

    $result = app(ClassificationService::class)->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['documentsUsed'])->toBe(2);
    expect($a->fresh()->used_for_training_at)->not->toBeNull()
        ->and($b->fresh()->used_for_training_at)->not->toBeNull();
});

test('trainingQueueStatus() (the ML Training page display) excludes a duplicate the same way autoTrainIfDue() does', function () {
    // Regression coverage for a real reported bug (confirmed 2026-10-03):
    // trainingQueueStatus() independently re-implements the same base
    // eligibility query as autoTrainIfDue() for display purposes, and was
    // missed when the confidence-ceiling and near-duplicate filters were
    // added to the real trigger — so the ML Training page kept SHOWING
    // documents (including literal duplicate re-uploads) that the actual
    // trigger had already stopped counting, directly contradicting this
    // method's own docblock claim that the two are "never out of sync."
    config(['ml.auto_train_confidence_ceiling' => 75]);
    bootstrapModel();

    $sharedText = distinctText('Job Order', 777);
    eligibleDoc('Job Order', ['ocr_text' => $sharedText]);
    eligibleDoc('Job Order', ['ocr_text' => $sharedText]); // duplicate of the one above
    eligibleDoc('Job Order', ['ml_confidence' => 90.0]); // above the confidence ceiling

    $status = app(ClassificationService::class)->trainingQueueStatus();

    expect($status['total_eligible'])->toBe(1)
        ->and($status['queue'])->toHaveCount(1);
});

test('a document used in one retrain still contributes to the NEXT model, instead of being forgotten', function () {
    // Regression coverage for a real reported bug (confirmed 2026-10-03):
    // every retrain builds a brand-new model from scratch, and the old
    // code permanently excluded a document from ever being fed into
    // training again the instant it was used once — so each new model
    // actually knew LESS about real documents than the one it replaced.
    // The fix makes the real-document portion of the corpus accumulate
    // (capped, FIFO) instead of being discarded after one use.
    config(['ml.auto_train_batch_size' => 1]);
    bootstrapModel();

    $first = eligibleDoc('Job Order');
    app(ClassificationService::class)->autoTrainIfDue();
    expect($first->fresh()->used_for_training_at)->not->toBeNull();

    // A spy on train() lets us inspect exactly what corpus the SECOND
    // retrain was actually handed, without needing a real (slow) SVM fit.
    $second = eligibleDoc('Job Order');
    $capturedCorpus = null;
    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function ($samplesByCategory) use (&$capturedCorpus) {
        $capturedCorpus = $samplesByCategory;
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-second',
            'accuracy_score' => 100.0,
            'model_file_path' => 'models/test.phpml',
            'training_sample_count' => 1,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $mock->autoTrainIfDue();

    // The FIRST document's text is still present in the corpus fed to the
    // SECOND retrain, even though it was already used in the first one.
    expect($capturedCorpus['Job Order'])->toContain($first->fresh()->ocr_text)
        ->and($capturedCorpus['Job Order'])->toContain($second->fresh()->ocr_text);
});

test('evicts the oldest-uploaded real document once a category\'s accumulated pool exceeds the cap', function () {
    // 5 curated for Job Order, ratio 2.0 -> cap of 10. Fill the pool to
    // exactly 10 across one retrain, then add one more real document on a
    // second retrain — the single OLDEST of the original 10 should drop
    // out of the corpus (though it stays stamped as used; see this
    // method's docblock on used_for_training_at's narrower meaning now).
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_max_auto_ratio' => 2.0]);
    bootstrapModel(perCategory: 5);

    $originalTen = collect(range(1, 10))->map(function ($i) {
        $doc = eligibleDoc('Job Order');
        $doc->created_at = now()->subMinutes(100 - $i); // ascending, oldest first
        $doc->save();

        return $doc;
    });
    app(ClassificationService::class)->autoTrainIfDue();
    $originalTen->each(fn ($d) => expect($d->fresh()->used_for_training_at)->not->toBeNull());

    $eleventh = eligibleDoc('Job Order');
    $eleventh->created_at = now();
    $eleventh->save();

    $capturedCorpus = null;
    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function ($samplesByCategory) use (&$capturedCorpus) {
        $capturedCorpus = $samplesByCategory;
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-eviction',
            'accuracy_score' => 100.0,
            'model_file_path' => 'models/test.phpml',
            'training_sample_count' => 1,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $mock->autoTrainIfDue();

    expect($capturedCorpus['Job Order'])->not->toContain($originalTen->first()->fresh()->ocr_text) // oldest evicted
        ->and($capturedCorpus['Job Order'])->toContain($originalTen->get(1)->fresh()->ocr_text) // 2nd-oldest kept
        ->and($capturedCorpus['Job Order'])->toContain($eleventh->fresh()->ocr_text); // newest kept
});

test('respects the per-category population cap, newest-uploaded-first (FIFO/replay-buffer)', function () {
    // 5 curated seed samples for Job Order, ratio 2.0 -> cap of 10 real
    // documents kept in the corpus per category. Changed 2026-10-03: the
    // real-document corpus now accumulates across retrains instead of
    // being discarded after one use (see autoTrainIfDue()'s docblock), so
    // ordering had to change from "oldest-waiting-gets-priority" (a fairness
    // rule that only made sense when each document got exactly one shot,
    // ever) to "most-recently-uploaded wins a spot" — a real document's
    // text needs to keep reflecting CURRENT usage as the window fills, not
    // whichever documents happened to queue up first. The 2 OLDEST stay
    // un-marked, eligible to compete for a spot on a later run.
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_max_auto_ratio' => 2.0]);
    bootstrapModel(perCategory: 5);

    $docs = collect(range(1, 12))->map(function ($i) {
        $doc = eligibleDoc('Job Order');
        $doc->created_at = now()->subMinutes(100 - $i); // ascending order, oldest first
        $doc->save();

        return $doc;
    });

    app(ClassificationService::class)->autoTrainIfDue();

    $usedCount = $docs->filter(fn ($d) => $d->fresh()->used_for_training_at !== null)->count();
    expect($usedCount)->toBe(10);

    // The two OLDEST documents (first in the oldest-first ordering) are the
    // ones left over the cap now.
    expect($docs->first()->fresh()->used_for_training_at)->toBeNull();
});

test('rolls back to the previous model when the new attempt scores worse, leaving the active model unchanged', function () {
    config(['ml.auto_train_batch_size' => 1]);
    $original = bootstrapModel();
    $originalAccuracy = $original->accuracy_score;

    eligibleDoc('Job Order');

    // andReturnUsing, not andReturn — the real train() deactivates every
    // previously-active model as part of registering the new one (see its
    // own "5. Register the new version" step), so the stub needs to
    // replicate that side effect too, not just hand back a model row.
    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original, $originalAccuracy) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-worse',
            'accuracy_score' => max(0.0, $originalAccuracy - 50.0),
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeFalse();

    expect($original->fresh()->is_active)->toBeTrue()
        ->and(MlModelRepository::active()->model_id)->toBe($original->model_id);
});

test('keeps the new model active when it scores at least as well as the previous one', function () {
    config(['ml.auto_train_batch_size' => 1]);
    $original = bootstrapModel();

    eligibleDoc('Job Order');

    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-better',
            'accuracy_score' => 100.0,
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeTrue()
        ->and($original->fresh()->is_active)->toBeFalse();

    expect(MlModelRepository::active()->version)->toBe('v-test-better');
});

test('keeps a small drop within the rollback tolerance instead of discarding it', function () {
    // The exact scenario this tolerance exists for: previous model at
    // 100% (nowhere to go but down or flat), new attempt at 97% — a
    // small, expected dip from testing against a bigger/more varied
    // pool, not a real regression. Default tolerance is 5 points.
    config(['ml.auto_train_batch_size' => 1, 'ml.auto_train_rollback_tolerance' => 5]);
    $original = bootstrapModel();
    $original->update(['accuracy_score' => 100.0]);

    eligibleDoc('Job Order');

    $mock = Mockery::mock(ClassificationService::class)->makePartial();
    $mock->shouldReceive('train')->once()->andReturnUsing(function () use ($original) {
        MlModelRepository::where('is_active', true)->update(['is_active' => false]);

        return MlModelRepository::create([
            'model_name' => 'Support Vector Machine (SVM) + TF-IDF',
            'version' => 'v-test-small-dip',
            'accuracy_score' => 97.0,
            'model_file_path' => $original->model_file_path,
            'training_sample_count' => 16,
            'is_active' => true,
            'last_trained' => now(),
        ]);
    });

    $result = $mock->autoTrainIfDue();

    expect($result)->not->toBeNull()
        ->and($result['kept'])->toBeTrue();

    expect(MlModelRepository::active()->version)->toBe('v-test-small-dip');
});
