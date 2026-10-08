<?php

use App\Models\DocumentRepository;
use App\Models\MlStagingSample;
use App\Models\User;
use App\Services\ValidationService;

/**
 * Stages 5 (the MIN_VOCABULARY_SAMPLES bar) real-ish samples for a category
 * so the readability check actually engages — with none staged, the check
 * is deliberately skipped (see "skips the readability check..." test
 * below), so any test exercising it needs this seeded first.
 */
function stageJobOrderVocabulary(): void
{
    $samples = [
        'Job Order No: JO-2026-1001. Date Requested: January 5, 2026. Requested By: Ana Reyes, Fleet Supervisor. '
            .'Description of Work: Perform scheduled servicing on the company delivery truck. Change the engine oil '
            .'and oil filter, inspect the brake pads and replace if worn, check the transmission fluid, and rotate the tires.',
        'Job Order No: JO-2026-1002. Date Requested: January 12, 2026. Requested By: Miguel Santos, Facilities Manager. '
            .'Description of Work: Repair the leaking water pump in the third floor conference room and inspect '
            .'nearby plumbing fixtures for similar wear before it causes further water damage to the ceiling.',
        'Job Order No: JO-2026-1003. Date Requested: January 20, 2026. Requested By: Carla Dizon, IT Coordinator. '
            .'Description of Work: Replace the faulty network switch in the server room and reconfigure the '
            .'affected ports so that all workstations on that floor regain stable network connectivity.',
        'Job Order No: JO-2026-1004. Date Requested: January 28, 2026. Requested By: Paulo Ramos, Operations Lead. '
            .'Description of Work: Install new air conditioning units in the warehouse loading area and test the '
            .'thermostat calibration to ensure consistent cooling throughout the storage space.',
        'Job Order No: JO-2026-1005. Date Requested: February 2, 2026. Requested By: Grace Tan, Administrative Assistant. '
            .'Description of Work: Service the office photocopier which has been jamming frequently, replace worn '
            .'rollers as needed, and confirm print quality is restored before returning it to daily use.',
    ];

    foreach ($samples as $i => $text) {
        MlStagingSample::create([
            'category' => 'Job Order',
            'original_filename' => "sample-{$i}.txt",
            'extracted_text' => $text,
        ]);
    }
}

test('a document with every required section and enough words passes validation', function () {
    $text = "JOB ORDER\nJob Order No: JO-2026-0001\nDate Requested: July 16, 2026\n"
        ."Requested By: Test Requester\nDescription of Work:\n"
        .'Perform scheduled servicing on the company delivery truck including an oil change, '
        ."brake inspection, tire rotation, and a full fluid level check before the next route.\n"
        .'Estimated Cost: PHP 3,500.00';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

test('a document missing a required section fails validation with a specific error', function () {
    $text = "JOB ORDER\nRequested By: Test Requester\n"
        .'Description of Work: some description that is long enough to pass the word count on its own here.';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeFalse()
        ->and($result['errors'])->toContain('Missing required section/field: "Date Requested"');
});

test('accepts a reasonable wording variant of a required field instead of demanding the exact phrase', function () {
    // "Requestor" instead of "Requested By" — same reasoning as the
    // original wording-mismatch fix this pattern was built for (see
    // ValidationService::TEMPLATES's own comment): a document only needs
    // ONE of a field's accepted phrasings, not the exact canonical one.
    $text = "JOB ORDER\nDate Requested: July 16, 2026\nRequestor: Test Requester\nDescription of Work:\n"
        .'Perform scheduled servicing on the company delivery truck including an oil change, '
        ."brake inspection, tire rotation, and a full fluid level check before the next route.\n"
        .'Estimated Cost: PHP 3,500.00';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

test('still fails when NONE of a field\'s accepted variants are present', function () {
    $text = "JOB ORDER\nDate Requested: July 16, 2026\nSubmitted For: Test Requester\nDescription of Work:\n"
        .'Perform scheduled servicing on the company delivery truck including an oil change, '
        .'brake inspection, tire rotation, and a full fluid level check before the next route.';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeFalse()
        ->and($result['errors'])->toContain('Missing required section/field: "Requested By"');
});

test('a document under the minimum word count fails validation', function () {
    $text = "JOB ORDER\nDate Requested: July 16, 2026\nRequested By: X\nDescription of Work: too short.";

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeFalse();
});

test('skips the readability check entirely when a category has too few staged samples (cold start)', function () {
    // Only 2 staged — under MIN_VOCABULARY_SAMPLES (5) — so there isn't
    // enough real vocabulary yet to score against. Even garbled text must
    // NOT be blocked here: guessing wrong with no data would be worse than
    // not checking at all.
    MlStagingSample::create(['category' => 'Job Order', 'original_filename' => 'a.txt', 'extracted_text' => 'a real job order sample here']);
    MlStagingSample::create(['category' => 'Job Order', 'original_filename' => 'b.txt', 'extracted_text' => 'another real job order sample']);

    $text = "JOB ORDER\nJob Order No: JO-2026-0002\nDate Requested: July 16, 2026\n"
        ."Requested By: X\nDescription of Work: "
        .'xkzq vbnm qzxc wplo zxvb mnbv qwop lkjh zxcv bnml qazw sxed cvfr tgby '
        ."hnuj mkio plaz wsxq edcr fvtg bnhy ujmk iolp zaws xedc rfvt gbnh yujm\n"
        .'Estimated Cost: PHP 3,500.00';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['readability_score'])->toBeNull();
});

test('a document that is mostly garbled/unrecognizable text scores low on readability but still passes validation', function () {
    stageJobOrderVocabulary();

    // Padded past min_word_count with keyboard-mashing so the readability
    // check — not the word-count gate — is what's actually being tested.
    // Readability is informational only now (see validate()'s docblock),
    // so this stays is_valid even though the score is low.
    $text = "JOB ORDER\nJob Order No: JO-2026-0002\nDate Requested: July 16, 2026\n"
        ."Requested By: X\nDescription of Work: "
        .'xkzq vbnm qzxc wplo zxvb mnbv qwop lkjh zxcv bnml qazw sxed cvfr tgby '
        ."hnuj mkio plaz wsxq edcr fvtg bnhy ujmk iolp zaws xedc rfvt gbnh yujm\n"
        .'Estimated Cost: PHP 3,500.00';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['readability_note'])->not->toBeNull()
        ->and($result['readability_score'])->not->toBeNull()
        ->and($result['readability_note'])->toContain('Scored low');
});

test('the readability vocabulary also draws from real documents already used in training, not curated samples alone', function () {
    stageJobOrderVocabulary();

    $originator = User::factory()->originator()->create();

    // A word that appears in NONE of stageJobOrderVocabulary()'s curated
    // samples — only a real, already-trained-on routed document teaches
    // it. Repeated enough to actually move the real-word ratio.
    $novelWord = 'thermogauge';
    DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'routed.txt',
        'file_path' => 'documents/routed.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
        'ocr_text' => str_repeat("{$novelWord} ", 20),
        'used_for_training_at' => now(),
    ]);

    $text = "JOB ORDER\nDate Requested: July 16, 2026\nRequested By: X\nDescription of Work: "
        .str_repeat("{$novelWord} ", 20);

    $result = app(ValidationService::class)->validate('Job Order', $text);

    // Without the routed document's vocabulary, "thermogauge" would be
    // unrecognized and this would score low/fail the readability check.
    expect($result['readability_note'])->toBeNull();
});

test('an ordinary business document clears the readability check even with domain jargon and proper nouns', function () {
    stageJobOrderVocabulary();

    $text = "JOB ORDER\nJob Order No: JO-2026-0188\nDate Requested: February 3, 2026\n"
        ."Requested By: Nestor Villanueva, Fleet Supervisor\nDepartment: Engineering\n"
        .'Description of Work: Perform scheduled servicing on the company delivery truck with plate number XPT-4471. '
        .'Change the engine oil and oil filter, inspect the brake pads and replace if worn beyond the safe limit, '
        ."check the transmission fluid, and rotate the tires. Test the battery charge and inspect all exterior lights.\n"
        .'Estimated Cost: PHP 3,500.00';

    $result = app(ValidationService::class)->validate('Job Order', $text);

    expect($result['is_valid'])->toBeTrue()
        ->and($result['readability_note'])->toBeNull();
});
