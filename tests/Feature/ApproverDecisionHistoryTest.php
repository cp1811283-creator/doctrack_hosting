<?php

use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;

function historyTestAssignment(User $approver, string $category, string $status, array $overrides = []): DocumentAssignment
{
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => $category, 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'history-test-'.uniqid().'.txt',
        'file_path' => 'documents/'.uniqid().'.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => $status === 'rejected' ? 'rejected' : 'approved',
        'ml_category' => $category,
    ]);

    return DocumentAssignment::create(array_merge([
        'document_id' => $document->document_id,
        'user_id' => $approver->user_id,
        'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date,
        'priority_rank' => 2,
        'individual_status' => $status,
        'acted_at' => now(),
        'sla_expires_at' => now()->addHours(3),
    ], $overrides));
}

it('lists this approver\'s own approved and rejected decisions', function () {
    $approver = User::factory()->approver('Job Order')->create();
    historyTestAssignment($approver, 'Job Order', 'approved');
    historyTestAssignment($approver, 'Job Order', 'rejected', ['comments' => 'Missing signature.']);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertOk();
    $response->assertSee('Approved');
    $response->assertSee('Rejected');
    $response->assertSee('Missing signature.');
});

it('labels a rejection reason "Rejected Due To:" in the expanded panel', function () {
    $approver = User::factory()->approver('Job Order')->create();
    historyTestAssignment($approver, 'Job Order', 'rejected', ['comments' => 'Missing signature.']);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee('Rejected Due To:');
});

it('excludes another approver\'s decisions', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $otherApprover = User::factory()->approver('Job Order')->create();
    $mine = historyTestAssignment($approver, 'Job Order', 'approved');
    $theirs = historyTestAssignment($otherApprover, 'Job Order', 'approved');

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee($mine->document->title);
    $response->assertDontSee($theirs->document->title);
});

it('excludes still-pending assignments from decision history', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $pending = historyTestAssignment($approver, 'Job Order', 'pending', ['acted_at' => null]);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertDontSee($pending->document->title);
});

it('includes auto-approved decisions with the Auto-Approved badge', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $auto = historyTestAssignment($approver, 'Job Order', 'auto_approved', ['auto_approved' => true]);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee($auto->document->title);
    $response->assertSee('Auto-Approved');
});

it('filters by keyword, category, and decision', function () {
    $approver = User::factory()->approver('Job Order')->create();
    historyTestAssignment($approver, 'Job Order', 'approved');
    $rejected = historyTestAssignment($approver, 'Job Order', 'rejected');

    $response = $this->actingAs($approver)->get(route('approver.history', ['decision' => 'rejected']));

    $response->assertSee($rejected->document->title);
    $response->assertViewHas('decisions', fn ($p) => $p->total() === 1);
});

it('wires the page to react to review-session activity, not just assignment routing', function () {
    // Regression coverage: this listener was missing entirely, so opening
    // the document viewer (which fires .document.review-activity the
    // instant a review session opens/closes — see DocumentReviewSession::
    // notifyStakeholders()) never reached this page's movement timeline,
    // unlike the Originator's tracking page (which already reacted to the
    // same activity via .document.status-changed, fired from that same
    // hook) — this page just never had the equivalent listener.
    $approver = User::factory()->approver('Job Order')->create();

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertOk();
    $response->assertSee('.document.review-activity', false);
    $response->assertSee('.assignment.routed', false);
});

it('the refresh endpoint returns only the results fragment', function () {
    $approver = User::factory()->approver('Job Order')->create();
    historyTestAssignment($approver, 'Job Order', 'approved');

    $response = $this->actingAs($approver)->get(route('approver.history.refresh'));

    $response->assertOk();
    $response->assertDontSee('<form', false);
    $response->assertSee('Decision History');
});

it('attributes a cascade-closed rejection to the co-approver who actually rejected it, not the seat holder', function () {
    $originator = User::factory()->originator()->create();
    $approver1 = User::factory()->approver('Job Order')->create(['full_name' => 'Approver One']);
    $approver2 = User::factory()->approver('Job Order')->create(['full_name' => 'Approver Two']);
    $stageA = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    $stageB = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'cascade-attribution-test.txt', 'file_path' => 'documents/cascade.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);
    $assignmentA = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver1->user_id, 'stage_id' => $stageA->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    $assignmentB = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver2->user_id, 'stage_id' => $stageB->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    seedReviewTime($approver1, $document);
    $this->actingAs($approver1)->post(route('approver.assignments.decide', $assignmentA), [
        'decision' => 'rejected',
        'comments' => 'Incorrect budget figures.',
    ]);

    $fresh = $assignmentB->fresh();
    expect($fresh->individual_status)->toBe('rejected');
    expect($fresh->cascade_closed_by)->toBe($approver1->user_id);

    // Approver 2's OWN decision history shows the document, but attributed
    // to Approver 1 — not implied as Approver 2's own call.
    $response = $this->actingAs($approver2)->get(route('approver.history'));
    $response->assertSee('cascade-attribution-test.txt');
    $response->assertSee('by Approver One');
});

it('attributes a rejection to the approver by name — always, even on a single-seat stage', function () {
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Self Decider']);
    historyTestAssignment($approver, 'Job Order', 'rejected', ['comments' => 'My own call.']);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee('by Self Decider');
    $response->assertDontSee('by You');
});

it('attributes a self-approval by name on a single-seat stage', function () {
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Solo Approver']);
    historyTestAssignment($approver, 'Job Order', 'approved');

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee('by Solo Approver');
});

it('names every approver on a multi-seat stage instead of crediting just one', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Joint Approver']);
    $coApprover = User::factory()->approver('Job Order')->create(['full_name' => 'Co Approver']);
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'joint-approval-history.txt', 'file_path' => 'documents/joint.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);
    // Sibling seat on the SAME stage — makes this a multi-seat (unanimous) stage.
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $coApprover->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee('joint-approval-history.txt');
    $response->assertSee('by Joint Approver and Co Approver');
});

it('merges every self-decided approval on a document into ONE summary badge naming every unique approver, even across different stage pairings', function () {
    // Regression: previously a document with a solo stage AND a joint
    // stage (or two joint stages with different co-approver pairs) showed
    // MULTIPLE summary badges that repeated the same name — e.g. "Approved
    // — by Lessur Vinz" next to "Approved — by Lessur Vinz and Vinz
    // Lessur" on the same row — which read as a duplicate/bug rather than
    // two distinct facts. The summary row now always collapses to one
    // badge per document; the expanded per-stage panel still shows the
    // exact accurate breakdown.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Lead Approver']);
    $coApprover = User::factory()->approver('Job Order')->create(['full_name' => 'Shared Co Approver']);
    $otherCoApprover = User::factory()->approver('Job Order')->create(['full_name' => 'Different Co Approver']);
    $stageA = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    $stageB = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $stageC = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage C', 'sequence_order' => 3]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'mixed-joint-history.txt', 'file_path' => 'documents/mixed-joint.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);

    // Stage A and Stage B share the exact same two approvers.
    foreach ([$stageA, $stageB] as $stage) {
        foreach ([$approver, $coApprover] as $who) {
            DocumentAssignment::create([
                'document_id' => $document->document_id, 'user_id' => $who->user_id, 'stage_id' => $stage->stage_id,
                'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
            ]);
        }
    }
    // Stage C is a DIFFERENT pair — $approver again, but a different co-approver.
    foreach ([$approver, $otherCoApprover] as $who) {
        DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $who->user_id, 'stage_id' => $stageC->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
        ]);
    }

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertSee('by Lead Approver, Shared Co Approver and Different Co Approver');
});

it('merges a mix of solo and joint stages on the same document into one summary badge', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Lessur Vinz']);
    $coApprover = User::factory()->approver('Job Order')->create(['full_name' => 'Vinz Lessur']);
    $soloStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $jointStage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'solo-plus-joint-history.txt', 'file_path' => 'documents/solo-plus-joint.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    // Joint stage: both approvers hold a seat.
    foreach ([$approver, $coApprover] as $who) {
        DocumentAssignment::create([
            'document_id' => $document->document_id, 'user_id' => $who->user_id, 'stage_id' => $jointStage->stage_id,
            'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
        ]);
    }
    // Solo stage: only $approver holds a seat.
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $soloStage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    // Filtered by keyword so this is the ONLY document in the response —
    // the unfiltered history page has many other "Approved" badges from
    // unrelated documents, which would make a raw count meaningless.
    $response = $this->actingAs($approver)->get(route('approver.history', ['keyword' => 'solo-plus-joint-history']));

    $response->assertViewHas('decisions', fn ($p) => $p->total() === 1);

    // Isolate just the summary "Decisions" cell (not the expanded
    // per-stage panel below it, which legitimately repeats the badge
    // class once per stage) and confirm exactly one badge renders there.
    preg_match('/<td class="px-6 py-3">(.*?)<\/td>/s', $response->getContent(), $matches);
    expect(substr_count($matches[1], 'bg-approved-50 text-approved-700'))->toBe(1);
    expect($matches[1])->toContain('by Lessur Vinz and Vinz Lessur');
});

it('groups multiple decisions on the same document into one row', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $stageA = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    $stageB = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'multi-stage-history.txt', 'file_path' => 'documents/multi.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stageA->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stageB->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved', 'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertOk();
    $response->assertViewHas('decisions', fn ($p) => $p->total() === 1); // one document, not two rows
});

it('attributes a past admin-overridden decision to the admin, not the approver it was assigned to', function () {
    // The Admin override action no longer exists, but decisions it recorded before
    // are still in the data and must keep displaying correctly in Decision History.
    $admin = User::factory()->admin()->create(['full_name' => 'Override Admin']);
    $approver = User::factory()->approver('Job Order')->create();
    $originator = User::factory()->originator()->create();
    $stage = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'admin-override-history.txt', 'file_path' => 'documents/admin-override.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'sla_expires_at' => now()->addHour(), 'acted_at' => now(),
        'admin_override_by' => $admin->user_id, 'admin_override_at' => now(),
    ]);

    // Still shows up in the ORIGINAL approver's own Decision History...
    $response = $this->actingAs($approver)->get(route('approver.history'));
    $response->assertSee('admin-override-history.txt');
    $response->assertSee('by Admin (Override Admin)');
    $response->assertDontSee('Approved — by You');

    // ...and the "Decided by Admin" filter isolates it correctly.
    $filtered = $this->actingAs($approver)->get(route('approver.history', ['decision' => 'admin_override']));
    $filtered->assertViewHas('decisions', fn ($p) => $p->total() === 1);
});

it('expands a document row to show the full movement timeline', function () {
    $approver = User::factory()->approver('Job Order')->create();
    $assignment = historyTestAssignment($approver, 'Job Order', 'approved');
    AuditLog::record($approver->user_id, $assignment->document_id, 'approve', 'Approved by approver.');

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertOk();
    $response->assertSee('history-movements-'.$assignment->document_id, false);
    // The old "Full movement history" caption is gone — <x-document-tracker>
    // (see resources/views/components/document-tracker.blade.php) supplies
    // its own "Document Tracker" label now.
    $response->assertSee('Document Tracker');
});

it('merges a self-triggered rejection cascade into ONE summary badge instead of two identical ones', function () {
    // Regression: an approver holding two pending stages on the same
    // document who rejects one of them cascades to auto-close their OWN
    // other pending stage too (see WorkflowService::completeStage()'s
    // rejection cascade, which closes every other pending assignment on
    // the document with no regard for who holds it). That second row's
    // cascade_closed_by is the SAME approver's own user_id, which the
    // attribution logic was treating as "closed by someone else" — same
    // display text ("Rejected — by Vinz Lessur"), but a different
    // grouping key, so it rendered as two identical-looking badges.
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'Self Cascade Approver']);
    $stageA = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage A', 'sequence_order' => 1]);
    $stageB = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Stage B', 'sequence_order' => 2]);
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'self-cascade-history.txt', 'file_path' => 'documents/self-cascade.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'classified_validated', 'ml_category' => 'Job Order',
    ]);
    $assignmentA = DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stageA->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stageB->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending', 'sla_expires_at' => now()->addHours(3),
    ]);

    seedReviewTime($approver, $document);
    $this->actingAs($approver)->post(route('approver.assignments.decide', $assignmentA), [
        'decision' => 'rejected',
        'comments' => 'Rejecting stage A.',
    ]);

    $response = $this->actingAs($approver)->get(route('approver.history', ['keyword' => 'self-cascade-history']));

    preg_match('/<td class="px-6 py-3">(.*?)<\/td>/s', $response->getContent(), $matches);
    expect(substr_count($matches[1], 'bg-rejected-50 text-rejected-700'))->toBe(1);
    expect($matches[1])->toContain('by Self Cascade Approver');
});

it('only offers the category dropdown options this approver could actually have a decision in', function () {
    // Regression coverage for a real reported gap: the dropdown used to
    // list all 3 known categories for every approver, so a Staff approver
    // picked for only one category had two filter options that could
    // never return a single result — see User::eligibleCategories().
    $approver = User::factory()->approver('Job Order')->create();

    $response = $this->actingAs($approver)->get(route('approver.history'));

    $response->assertOk();
    $response->assertSee('<option value="Job Order"', false);
    $response->assertDontSee('<option value="Purchase Requisition"', false);
    $response->assertDontSee('<option value="Service Report"', false);
});

it('offers a Head every category their department covers for Final Approval, not just one', function () {
    $financeHead = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head', 'department' => 'Finance']);
    foreach (['Job Order', 'Purchase Requisition'] as $category) {
        $finalApproval = WorkflowStage::firstOrCreate(
            ['document_category' => $category, 'stage_name' => 'Final Approval'],
            ['sequence_order' => 99]
        );
        WorkflowStageDepartment::create(['stage_id' => $finalApproval->stage_id, 'department' => 'Finance']);
    }

    $response = $this->actingAs($financeHead)->get(route('approver.history'));

    $response->assertOk();
    $response->assertSee('<option value="Job Order"', false);
    $response->assertSee('<option value="Purchase Requisition"', false);
    $response->assertDontSee('<option value="Service Report"', false);
});

it('always offers "Other" as a category option, and selecting it filters correctly', function () {
    // Regression coverage: the dropdown never offered "Other" at all, and
    // even selecting it manually wouldn't have worked — the filter only
    // matched the real ml_category column, with no special case for a
    // document flagged "doesn't belong to any category" (see
    // ArchiveController::OTHER_FOLDER's identical reasoning).
    $approver = User::factory()->approver('Job Order')->create();
    $stage = WorkflowStage::firstOrCreate(
        ['document_category' => 'Job Order', 'stage_name' => 'Review'],
        ['sequence_order' => 1]
    );
    $originator = User::factory()->originator()->create();

    $otherDoc = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'other-decision.txt', 'file_path' => 'documents/o.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(), 'global_status' => 'approved',
        'ml_category' => 'Job Order', 'desired_routing' => 'unrelated',
    ]);
    DocumentAssignment::create([
        'document_id' => $otherDoc->document_id, 'user_id' => $approver->user_id, 'stage_id' => $stage->stage_id,
        'due_date' => $otherDoc->due_date, 'priority_rank' => 2, 'individual_status' => 'approved',
        'acted_at' => now(), 'sla_expires_at' => now()->addHours(3),
    ]);
    // A real Job Order decision — must NOT show up under the Other filter.
    historyTestAssignment($approver, 'Job Order', 'approved');

    $page = $this->actingAs($approver)->get(route('approver.history'));
    $page->assertOk()->assertSee('<option value="Other"', false);

    $filtered = $this->actingAs($approver)->get(route('approver.history', ['category' => 'Other']));
    $filtered->assertOk()
        ->assertSee('other-decision.txt')
        ->assertDontSee('history-test-');
});
