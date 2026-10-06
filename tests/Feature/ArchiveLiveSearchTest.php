<?php

use App\Models\DocumentRepository;
use App\Models\User;

test('the folder-grid screen shows no search bar and no import panel', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.archive'));

    $response->assertOk();
    $response->assertDontSee('Search all categories');
    $response->assertDontSee('Import Legacy Document');
});

test('the drill-down view (inside a category) shows both the search bar and import panel for an admin', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Keyword');
    $response->assertSee('Import Legacy Document');
});

test('the refresh endpoint returns only the results fragment, not the full page', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'live-search-target.txt',
        'file_path' => 'documents/x.txt', 'mime_type' => 'text/plain',
        'due_date' => now()->addDay(), 'upload_date' => now(),
        'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($admin)->get(route('archive.refresh', ['keyword' => 'live-search-target']));

    $response->assertOk();
    $response->assertSee('live-search-target.txt');
    $response->assertSee('Approved Documents');
    $response->assertDontSee('<html', false); // fragment only — no full-page wrapper
    $response->assertDontSee('Browse by Category');
});

test('the refresh endpoint enforces the same RBAC as the full page for an approver', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'other-category.txt',
        'file_path' => 'documents/y.txt', 'mime_type' => 'text/plain',
        'due_date' => now()->addDay(), 'upload_date' => now(),
        'global_status' => 'approved', 'ml_category' => 'Service Report',
    ]);

    // Attempting to filter by a category outside the approver's own is
    // simply ignored server-side, same as the full index() already does.
    $response = $this->actingAs($approver)->get(route('archive.refresh', ['category' => 'Service Report']));

    $response->assertOk();
    $response->assertDontSee('other-category.txt');
});

test('refresh works for an approver with no assigned category (heads), same as the full page', function () {
    $approver = User::factory()->approver()->create(['assigned_category' => null]);

    $this->actingAs($approver)->get(route('archive.refresh'))->assertOk();
});
