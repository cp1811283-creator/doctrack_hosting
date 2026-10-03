<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;

function violationIn(string $category, string $stageName = 'Review'): SlaViolation
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver($category)->create();
    // firstOrCreate, not create — this helper is called more than once per
    // test with the same (category, stageName) defaults, and a real unique
    // constraint on active category-wide stages (added 2026-10-03, after a
    // genuine production duplicate-stage bug) now correctly catches what a
    // second create() call here would be: a real duplicate stage.
    $stage = WorkflowStage::firstOrCreate(['document_category' => $category, 'stage_name' => $stageName], ['sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => "{$category}-".uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'upload_date' => now(),
        'global_status' => 'auto_approved',
        'ml_category' => $category,
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => 'approved',
        'auto_approved' => true,
        'acted_at' => now(),
        'sla_expires_at' => now()->subHours(13),
    ]);

    return SlaViolation::create([
        'document_id' => $document->document_id,
        'assignment_id' => $assignment->assignment_id,
        'approver_id' => $approver->user_id,
        'violation_timestamp' => now(),
        'duration_overdue' => 30,
        'stage_name' => $stageName,
    ]);
}

test('visiting the bare SLA violations URL shows category folders, not the approver/admin tables', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));

    $response->assertOk();
    $response->assertSee('Browse by Category');
    $response->assertSee('Job Order');
    $response->assertDontSee('Approvers — Violation Counts');
});

test('the stat cards and approver roster stay hidden on the folder-grid screen, so nothing looks pre-filtered before a category is picked', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations'));

    $response->assertOk();
    $response->assertDontSee('Total Violations');
    $response->assertDontSee('Most Violations');
    $response->assertDontSee('Approvers — Violation Counts');
});

test('the stat cards and approver table appear once a category is picked, scoped to it', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Total Violations');
    $response->assertSee('Most Violations');
    $response->assertSee('Approvers — Violation Counts');
    $response->assertSee('Disputed');
});

test('clicking into a category folder shows the Admin/Approver tables and hides the folder grid', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Admin Violations');
    $response->assertSee('Approvers — Violation Counts');
    $response->assertDontSee('Browse by Category');
});

test('the Disputed card counts violations whose document was later disputed', function () {
    $admin = User::factory()->admin()->create();
    $violation = violationIn('Job Order');
    violationIn('Job Order'); // a second, non-disputed one

    $violation->document->update(['disputed_at' => now()]);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    expect($response->viewData('disputedCount'))->toBe(1);
});
