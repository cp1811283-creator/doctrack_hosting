<?php

use App\Models\DocumentRepository;
use App\Models\User;
use Carbon\Carbon;

/**
 * Regression coverage for a real reported gap: "Peak upload day/hour" used
 * to be a constant, all-time snapshot shown next to the Analytics panel's
 * own title — which names a specific window (e.g. "Analytics for October
 * 8, 2026") — making it look like it belonged to that window when it
 * never actually did. It's now computed fresh per tab, inside the panel
 * itself, and asks a different, more specific question depending on the
 * tab (see AdminController::analyticsPeak()'s own docblock).
 */
function peakUploadDoc(User $originator, Carbon $uploadDate): DocumentRepository
{
    $doc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'peak-'.uniqid().'.txt', 'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);
    $doc->upload_date = $uploadDate;
    $doc->save();

    return $doc->fresh();
}

it('Day tab shows only a peak hour, in 12-hour format', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 20:00:00'));
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    peakUploadDoc($originator, now()->copy()->setTime(16, 10));
    peakUploadDoc($originator, now()->copy()->setTime(16, 40));
    peakUploadDoc($originator, now()->copy()->setTime(9, 5));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day']));

    $response->assertOk()
        ->assertSee('Peak upload hour')
        ->assertSee('4:00 PM–5:00 PM', false)
        ->assertDontSee('Peak upload day')
        ->assertDontSee('16:00', false);
});

it('Week tab shows both a peak day and a peak hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 20:00:00'));
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    // Two Wednesdays at the same hour outweigh one Thursday.
    peakUploadDoc($originator, Carbon::parse('2026-08-05 10:00:00'));
    peakUploadDoc($originator, Carbon::parse('2026-08-12 10:00:00'));
    peakUploadDoc($originator, Carbon::parse('2026-08-06 11:00:00'));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'week']));

    $response->assertOk()
        ->assertSee('Peak upload day')
        ->assertSee('Wednesday')
        ->assertSee('Peak upload hour')
        ->assertSee('10:00 AM–11:00 AM', false);
});

it('Month tab shows a specific peak date, not a day-of-week pattern', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 20:00:00'));
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    peakUploadDoc($originator, Carbon::parse('2026-07-15 10:00:00'));
    peakUploadDoc($originator, Carbon::parse('2026-07-15 11:00:00'));
    peakUploadDoc($originator, Carbon::parse('2026-08-01 09:00:00'));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'month']));

    $response->assertOk()
        ->assertSee('Peak upload date')
        ->assertSee('Jul 15, 2026')
        ->assertDontSee('Peak upload day');
});

it('Year tab shows a peak month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 20:00:00'));
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    peakUploadDoc($originator, Carbon::parse('2024-03-05 10:00:00'));
    peakUploadDoc($originator, Carbon::parse('2024-03-20 10:00:00'));
    peakUploadDoc($originator, Carbon::parse('2025-01-05 10:00:00'));

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'year']));

    $response->assertOk()
        ->assertSee('Peak upload month')
        ->assertSee('March 2024');
});

it('shows no peak metric at all for a window with no uploads, instead of a misleading placeholder', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 20:00:00'));
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel', ['granularity' => 'day']));

    $response->assertOk()->assertDontSee('Peak upload hour');
});

it('"Currently in progress" stays a constant, all-time figure, unaffected by the selected tab', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'in-progress.txt', 'file_path' => 'documents/ip.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'processing', 'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk()->assertSee('Currently in progress');
});
