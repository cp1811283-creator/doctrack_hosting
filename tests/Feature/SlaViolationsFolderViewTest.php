<?php

use App\Models\AdminViolation;
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
    $response->assertDontSee('Approver SLA Violations');
    $response->assertDontSee('Most Violations');
    $response->assertDontSee('Approvers — Violation Counts');
});

test('the stat cards and approver table appear once a category is picked, scoped to it', function () {
    $admin = User::factory()->admin()->create();
    violationIn('Job Order');
    violationIn('Service Report');

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Approver SLA Violations');
    $response->assertSee('Most Violations');
    $response->assertSee('Approvers — Violation Counts');
    $response->assertSee('Admin Violations');
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

/**
 * Replaces the old "Disputed card" test (removed along with that card —
 * see sla_violations.blade.php's own comment on why: Control Center
 * already shows an accurate, correctly-counted Disputed figure, and this
 * page's version counted SlaViolation rows instead of documents, a
 * confirmed real bug not worth re-fixing for a redundant number). The
 * Admin Violations card that replaced it reuses adminViolationTotal
 * (already covered elsewhere), scoped to the picked category and never
 * mixing in another category's count.
 */
test('the Admin Violations card is scoped to the picked category', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Technical Review'],
        ['sequence_order' => 1]
    );
    $otherStage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Service Report', 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );

    $makeAdminViolation = function (string $category, WorkflowStage $stage) use ($originator) {
        $document = DocumentRepository::create([
            'originator_id' => $originator->user_id, 'title' => "{$category}-".uniqid().'.txt',
            'file_path' => 'documents/'.uniqid().'.txt', 'mime_type' => 'text/plain',
            'due_date' => now()->addDays(5), 'global_status' => 'auto_approved', 'ml_category' => $category,
        ]);
        $assignment = DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $originator->user_id, 'stage_id' => $stage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
            'auto_approved' => true, 'acted_at' => now(),
        ]);
        AdminViolation::create([
            'document_id' => $document->document_id, 'assignment_id' => $assignment->assignment_id,
            'violation_type' => 'late_review', 'stage_name' => $stage->stage_name, 'first_violated_at' => now(),
        ]);
    };

    $makeAdminViolation('Job Order', $stage);
    $makeAdminViolation('Job Order', $stage);
    $makeAdminViolation('Service Report', $otherStage);

    $response = $this->actingAs($admin)->get(route('admin.sla.violations', ['category' => 'Job Order']));

    expect($response->viewData('adminViolationTotal'))->toBe(2);
});
