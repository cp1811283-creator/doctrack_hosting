<?php

use App\Models\DocumentRepository;
use App\Models\User;
use Carbon\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-08-12 15:00:00')));

it('downloads the Analytics panel as a CSV titled with the exact span, including real data', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'csv-doc.txt', 'file_path' => 'documents/csv-doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'approved', 'upload_date' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel.download', ['granularity' => 'day']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    $csv = $response->streamedContent();

    expect($csv)->toContain('Analytics for August 12, 2026')
        ->toContain('Uploaded,Approved,Rejected,Auto-Approved,"Avg Minutes to Decide","SLA Violations"')
        ->toContain('"Aug 12, 2026, 3:00 PM",0,1,0,0,');
});

it('includes every period in the span, even ones with nothing in them, instead of silently dropping them', function () {
    // Regression coverage for a real reported bug: a quiet period used to
    // be filtered out of the CSV entirely, so a day with no activity came
    // back as a file with only a header row — indistinguishable from the
    // download being broken. Every one of the Day tab's 24 hourly rows
    // must be present regardless of whether anything happened in them.
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.dashboard.analyticsPanel.download', ['granularity' => 'day']));

    $csv = $response->streamedContent();
    $dataRows = array_filter(explode("\n", trim($csv)));

    // 1 title line + 1 header line + 24 hourly rows.
    expect($dataRows)->toHaveCount(26)
        ->and($csv)->toContain('"Aug 12, 2026, 12:00 AM",0,0,0,0,,0');
});

it('only admin can download the analytics CSV', function () {
    $originator = User::factory()->originator()->create();

    $this->actingAs($originator)->get(route('admin.dashboard.analyticsPanel.download'))->assertForbidden();
});
