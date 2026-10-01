<?php

use App\Models\DocumentRepository;
use App\Models\User;
use App\Services\ClassificationService;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for a real reported bug (confirmed 2026-10-01 in
 * production): the Submit button had no double-click guard, so a second
 * click before the first request's response landed fired a second, fully
 * independent form submission — two DocumentRepository rows for the same
 * file, 3 seconds apart. The client-side fix (disabling the button on
 * submit — see originator/dashboard.blade.php) isn't reachable from a Pest
 * HTTP test, so this covers the server-side backstop instead (see
 * DocumentController::isRecentDuplicateSubmission()).
 */
function mockClassificationAsJobOrder(): void
{
    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 90, 'margin' => 10.0, 'model_id' => null]);
    app()->instance(ClassificationService::class, $mock);
}

it('skips a second identical submission that lands moments after the first, instead of creating a duplicate document', function () {
    $originator = User::factory()->originator()->create();
    mockClassificationAsJobOrder();

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00')); // Wednesday, business hours

    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);
    $dueDate = now()->addHours(4)->format('Y-m-d\TH:i');

    $first = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => $dueDate,
    ]);
    $first->assertRedirect(route('originator.dashboard'));

    // document_repository.upload_date is a DB-level useCurrent() default,
    // which (unlike every app-layer now() call) doesn't respect Carbon::
    // setTestNow() — it always reflects the real test-run wall clock. Pin
    // it explicitly to the fake "first submission" moment so the recency
    // check below compares like-for-like fake timestamps, the same way
    // production's real app clock and real DB clock already naturally
    // agree with each other.
    DocumentRepository::where('originator_id', $originator->user_id)->update(['upload_date' => now()]);

    // Same originator, same filename, same byte size, moments later —
    // exactly what a double-clicked Submit button produces.
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:03'));

    $second = $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => $dueDate,
    ]);
    $second->assertRedirect(route('originator.dashboard'));
    $second->assertSessionHas('status', fn ($status) => str_contains($status, 'duplicate'));

    expect(DocumentRepository::where('originator_id', $originator->user_id)->where('original_filename', 'memo.txt')->count())->toBe(1);
});

it('still accepts a second submission of the same filename once comfortably past the duplicate window', function () {
    $originator = User::factory()->originator()->create();
    mockClassificationAsJobOrder();

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00'));

    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);
    $dueDate = now()->addHours(4)->format('Y-m-d\TH:i');

    $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => $dueDate,
    ])->assertRedirect(route('originator.dashboard'));

    // See the matching comment in the "skips" test above — upload_date's
    // DB-level useCurrent() default ignores Carbon::setTestNow(), so it
    // needs pinning explicitly to keep this test's "30 seconds later"
    // premise real relative to the check's own now()->subSeconds(10).
    DocumentRepository::where('originator_id', $originator->user_id)->update(['upload_date' => now()]);

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:30')); // 30s later — past the 10s window

    $this->actingAs($originator)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => now()->addHours(4)->format('Y-m-d\TH:i'),
    ])->assertRedirect(route('originator.dashboard'));

    expect(DocumentRepository::where('originator_id', $originator->user_id)->where('original_filename', 'memo.txt')->count())->toBe(2);
});

it('does not treat a genuinely different file with the same name from another originator as a duplicate', function () {
    $originatorA = User::factory()->originator()->create();
    $originatorB = User::factory()->originator()->create();
    mockClassificationAsJobOrder();

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-12 10:00:00'));

    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);
    $dueDate = now()->addHours(4)->format('Y-m-d\TH:i');

    $this->actingAs($originatorA)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => $dueDate,
    ])->assertRedirect(route('originator.dashboard'));

    $this->actingAs($originatorB)->post(route('originator.documents.store'), [
        'files' => [UploadedFile::fake()->createWithContent('memo.txt', $content)],
        'due_date' => $dueDate,
    ])->assertRedirect(route('originator.dashboard'));

    expect(DocumentRepository::where('original_filename', 'memo.txt')->count())->toBe(2);
});
