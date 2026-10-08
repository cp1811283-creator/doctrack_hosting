<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalForecastService;
use App\Services\BusinessHoursService;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

/**
 * The classifier is mocked to a fixed category/confidence rather than
 * relying on the real trained SVM's output for arbitrary test content —
 * there's no active trained model in a fresh test DB at all, so a real
 * classify() call always returns 'Other' at 0% — see
 * PrintRequiredFlagTest's identical helper for the same reasoning.
 */
function fakeClassifiedIngestWithRoutingMode(string $category, float $confidence, string $content, string $routingMode): DocumentRepository
{
    $originator = User::factory()->originator()->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => $category, 'confidence' => $confidence, 'margin' => 100.0, 'model_id' => null]);
    // See the matching comment in OtherDisplayTest.php — WorkflowService::
    // ingest() dispatches CheckAutoTrainDue, which runs inline under the
    // test suite's sync queue connection against this same strict mock.
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    return app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', $content),
        $originator,
        now()->addDay()->toDateTimeString(),
        null, null, false, $routingMode,
    );
}

test('routing_mode custom still classifies and validates normally, but waits for the originator to pick approvers instead of auto-routing', function () {
    User::factory()->approver('Job Order')->create();

    // Matches the Job Order template's required_sections + min_word_count
    // (30 words) — these tests aren't about classification/validation
    // itself, just about what happens to routing once both succeed.
    $content = "Job Order No: JO-1\nDate Requested: today\nRequested By: someone\nDescription of Work: ".
        str_repeat('fix the widget assembly line carefully and thoroughly ', 5)
        ."\nEstimated Cost: PHP 3,500.00";

    $document = fakeClassifiedIngestWithRoutingMode('Job Order', 95, $content, 'custom');

    expect($document->desired_routing)->toBe('custom')
        ->and($document->is_validated)->toBeTrue()
        ->and($document->ml_category)->toBe('Job Order')
        ->and($document->pending_custom_routing_at)->not->toBeNull()
        ->and($document->custom_routed)->toBeFalse()
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(0);
});

test('routing_mode unrelated skips category validation and classification-confidence review, applying the generic check instead', function () {
    // Deliberately does NOT match the Job Order template's required
    // sections at all — under routing_mode 'auto' this would fail
    // validation outright. It's long enough to clear the generic
    // word-count-only check instead. Confidence is deliberately LOW
    // (well under the review threshold) to prove that never gates this
    // mode either.
    $content = str_repeat('This is a perfectly ordinary internal memo about scheduling and staffing. ', 5);

    $document = fakeClassifiedIngestWithRoutingMode('Job Order', 20, $content, 'unrelated');

    expect($document->desired_routing)->toBe('unrelated')
        ->and($document->is_validated)->toBeTrue()
        ->and($document->ml_category)->toBe('Job Order') // classifier's best guess, kept for reference only
        ->and($document->ml_review_status)->not->toBe('pending') // low confidence on the guess never gates this
        ->and($document->pending_custom_routing_at)->not->toBeNull();
});

test('an originator can select approvers for a custom-routed document, and it behaves like any other stage', function () {
    $originator = User::factory()->originator()->create();
    $approverA = User::factory()->approver('Job Order')->create(['full_name' => 'Approver A']);
    $approverB = User::factory()->approver('Job Order')->create(['full_name' => 'Approver B']);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.routeCustom', $document), [
        'approver_ids' => [$approverA->user_id, $approverB->user_id],
    ]);

    $response->assertRedirect(route('originator.documents.show', $document));

    $document->refresh();
    expect($document->pending_custom_routing_at)->toBeNull()
        ->and($document->custom_routed)->toBeTrue();

    $assignments = DocumentAssignment::where('document_id', $document->document_id)->get();
    expect($assignments)->toHaveCount(2)
        ->and($assignments->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$approverA->user_id, $approverB->user_id])->sort()->values()->all());

    $stage = $assignments->first()->stage;
    expect($stage->document_id)->toBe($document->document_id);

    // Approval still needs BOTH — same majority-vote machinery every
    // other stage already uses, no separate voting logic for this path.
    $assignments->first()->update(['individual_status' => 'approved', 'acted_at' => now()]);
    app(WorkflowService::class)->completeStage($assignments->first()->fresh(), 'approved');
    expect($document->fresh()->global_status)->toBe('classified_validated');

    $assignments->last()->update(['individual_status' => 'approved', 'acted_at' => now()]);
    app(WorkflowService::class)->completeStage($assignments->last()->fresh(), 'approved');
    expect($document->fresh()->global_status)->toBe('approved');
});

test('only the owning originator can select approvers for a custom-routed document', function () {
    $originator = User::factory()->originator()->create();
    $otherOriginator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    $this->actingAs($otherOriginator)->get(route('originator.documents.selectApprovers', $document))->assertForbidden();
    $this->actingAs($otherOriginator)->post(route('originator.documents.routeCustom', $document), [
        'approver_ids' => [$approver->user_id],
    ])->assertForbidden();
});

test('a document cannot be routed to an approver ineligible for its category', function () {
    $originator = User::factory()->originator()->create();
    $wrongApprover = User::factory()->approver('Purchase Requisition')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.routeCustom', $document), [
        'approver_ids' => [$wrongApprover->user_id],
    ]);

    $response->assertSessionHasErrors('approver_ids.0');
    expect($document->fresh()->pending_custom_routing_at)->not->toBeNull();
});

test('the approver picker for an unrelated document offers every active approver, not just one category', function () {
    $originator = User::factory()->originator()->create();
    $jobOrderApprover = User::factory()->approver('Job Order')->create();
    $purchaseApprover = User::factory()->approver('Purchase Requisition')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'weird.txt', 'file_path' => 'documents/weird.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'unrelated',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->post(route('originator.documents.routeCustom', $document), [
        'approver_ids' => [$jobOrderApprover->user_id, $purchaseApprover->user_id],
    ]);

    $response->assertRedirect();
    expect(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(2);
});

test('the tracking page shows only the custom stage for a custom-routed document, not the bypassed category pipeline', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create(['full_name' => 'QA Approver']);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    // Still awaiting the pick — no bypassed-pipeline stages, and no bare
    // empty card either (see workflow-stage-list.blade.php's placeholder).
    $awaiting = $this->actingAs($originator)->get(route('originator.documents.show', $document));
    $awaiting->assertOk()
        ->assertDontSee('Technical Review')
        ->assertDontSee('Final Approval')
        ->assertSee("Awaiting the originator's approver selection", false);

    app(WorkflowService::class)->routeToCustomApprovers($document, [$approver->user_id], $originator);

    $routed = $this->actingAs($originator)->get(route('originator.documents.show', $document->fresh()));
    $routed->assertOk()
        ->assertSee('Direct Approval')
        ->assertDontSee('Technical Review')
        ->assertDontSee('Final Approval');
});

test('custom-routing to a Head names the one-off stage Final Approval, not the generic Direct Approval fallback', function () {
    // Regression coverage for a real reported bug: a Head never has a
    // specific stage pick of their own (eligible by level, not by pick —
    // see eligibleApproversForStage()'s docblock), so picking one used to
    // fall through to the same "Direct Approval" fallback a Staff
    // approver with no stage restriction gets — even though a Head only
    // ever means Final Approval.
    $originator = User::factory()->originator()->create();
    $head = User::factory()->approver()->create(['assigned_category' => null, 'level' => 'head', 'department' => 'Engineering', 'full_name' => 'Head Approver']);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'head-custom.txt', 'file_path' => 'documents/head-custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    app(WorkflowService::class)->routeToCustomApprovers($document, [$head->user_id], $originator);

    $routed = $this->actingAs($originator)->get(route('originator.documents.show', $document->fresh()));
    $routed->assertOk()
        ->assertSee('Final Approval')
        ->assertDontSee('Direct Approval');
});

test('the estimated approval time for a custom-routed document reflects its real SLA deadline, not the bypassed pipeline', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    // Nothing to estimate yet — no assignment/deadline exists until routed.
    expect(app(ApprovalForecastService::class)->estimateFor($document))->toBeNull();

    app(WorkflowService::class)->routeToCustomApprovers($document, [$approver->user_id], $originator);
    $document->refresh();

    $assignment = DocumentAssignment::where('document_id', $document->document_id)->first();
    $estimate = app(ApprovalForecastService::class)->estimateFor($document);

    expect($estimate)->not->toBeNull();
    // Business-hours-aware, not a plain wall-clock diff — the estimate
    // is re-expanded via addBusinessMinutes() wherever it's rendered
    // (see workflow-stage-list.blade.php), so it has to be measured with
    // that same business-hours ruler (businessSecondsRemaining(), its
    // exact mirror image) to land back on the real SLA deadline.
    $expectedSeconds = app(BusinessHoursService::class)
        ->businessSecondsRemaining(now(), $assignment->sla_expires_at);
    expect($estimate->totalSeconds)->toBeGreaterThan($expectedSeconds - 5)
        ->and($estimate->totalSeconds)->toBeLessThan($expectedSeconds + 5);
});

test('the approver picker groups by department with heads listed before staff', function () {
    $originator = User::factory()->originator()->create();
    $engStaff = User::factory()->approver('Job Order')->create(['full_name' => 'Zed Engineer', 'department' => 'Engineering', 'level' => 'staff']);
    $engHead = User::factory()->approver('Job Order')->create(['full_name' => 'Amy Head', 'department' => 'Engineering', 'level' => 'head']);
    $financeStaff = User::factory()->approver('Job Order')->create(['full_name' => 'Fin Person', 'department' => 'Finance', 'level' => 'staff']);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.selectApprovers', $document));
    $response->assertOk();

    $content = $response->getContent();
    // Within Engineering, the head appears before the staff member even
    // though "Amy" alphabetically precedes "Zed" anyway — check the
    // actual level badge ordering, not just name order, by confirming
    // "Head" appears before "Staff" in the raw output for this group.
    $headPos = strpos($content, 'Amy Head');
    $staffPos = strpos($content, 'Zed Engineer');
    expect($headPos)->not->toBeFalse()
        ->and($staffPos)->not->toBeFalse()
        ->and($headPos)->toBeLessThan($staffPos);

    $response->assertSee('Engineering')
        ->assertSee('Finance')
        ->assertSee('Head')
        ->assertSee('Staff');
});

test('the approver picker groups by Category -> Stage -> Approver, an unrestricted approver appearing under every stage in their category', function () {
    $originator = User::factory()->originator()->create();
    $stage1 = WorkflowStage::where('document_category', 'Job Order')->where('stage_name', 'Technical Review')->first();
    $stage2 = WorkflowStage::where('document_category', 'Job Order')->where('stage_name', 'Final Approval')->first();

    // level: 'head' on both — Final Approval now requires it (see
    // WorkflowService::eligibleApproversForStage()'s head-only rule);
    // without it neither approver would be eligible for $stage2 at all,
    // and this test is about STAGE grouping, not the head/staff distinction.
    $restricted = User::factory()->approver('Job Order')->create(['full_name' => 'Restricted Approver', 'level' => 'head']);
    $restricted->workflowStages()->sync([$stage2->stage_id]);

    $unrestricted = User::factory()->approver('Job Order')->create(['full_name' => 'Unrestricted Approver', 'level' => 'head']);
    // No sync() call — no explicit picks at all, so eligible for every
    // configured stage in their category (see WorkflowService::
    // eligibleApproversForStage()'s docblock).

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.selectApprovers', $document));
    $response->assertOk();

    $content = $response->getContent();
    $categoryPos = strpos($content, 'Job Order');
    $stage1Pos = strpos($content, $stage1->stage_name);
    $stage2Pos = strpos($content, $stage2->stage_name);
    $restrictedInStage1 = strpos($content, 'Restricted Approver', $stage1Pos);
    $unrestrictedInStage1 = strpos($content, 'Unrestricted Approver', $stage1Pos);
    $unrestrictedInStage2 = strpos($content, 'Unrestricted Approver', $stage2Pos);

    // Category heads the whole group, above both stage subheadings.
    expect($categoryPos)->not->toBeFalse()
        ->and($stage1Pos)->not->toBeFalse()->and($stage2Pos)->not->toBeFalse()
        ->and($categoryPos)->toBeLessThan($stage1Pos)
        ->and($categoryPos)->toBeLessThan($stage2Pos)
        // The unrestricted approver appears under BOTH stages — eligible
        // for every stage in the category, not just one.
        ->and($unrestrictedInStage1)->not->toBeFalse()
        ->and($unrestrictedInStage2)->not->toBeFalse()
        // The restricted approver only appears under Final Approval — the
        // one stage they were explicitly synced to — never under Technical
        // Review at all (strpos search bounded to before Final Approval's
        // heading, so this only finds a genuine Technical Review listing).
        ->and($restrictedInStage1 === false || $restrictedInStage1 > $stage2Pos)->toBeTrue();
});

test('the unrelated picker labels each approver with their own category, since it spans every category at once', function () {
    $originator = User::factory()->originator()->create();
    WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Budget Sign-off', 'sequence_order' => 1]);

    User::factory()->approver('Job Order')->create(['full_name' => 'Job Order Approver']);
    User::factory()->approver('Purchase Requisition')->create(['full_name' => 'PR Approver']);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'weird.txt', 'file_path' => 'documents/weird.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'unrelated',
        'pending_custom_routing_at' => now(),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.selectApprovers', $document));

    $response->assertOk()
        ->assertSee('Job Order —', false)
        ->assertSee('Purchase Requisition —', false);
});

test('a rejected custom-routed document requires a majority just like any other multi-seat stage', function () {
    $originator = User::factory()->originator()->create();
    $a = User::factory()->approver('Job Order')->create();
    $b = User::factory()->approver('Job Order')->create();
    $c = User::factory()->approver('Job Order')->create();

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id,
        'title' => 'custom.txt', 'file_path' => 'documents/custom.txt', 'mime_type' => 'text/plain',
        'ml_category' => 'Job Order', 'is_validated' => true, 'due_date' => now()->addDay(),
        'global_status' => 'classified_validated', 'desired_routing' => 'custom',
        'pending_custom_routing_at' => now(),
    ]);

    app(WorkflowService::class)->routeToCustomApprovers($document, [$a->user_id, $b->user_id, $c->user_id], $originator);

    $assignmentA = DocumentAssignment::where('document_id', $document->document_id)->where('user_id', $a->user_id)->first();
    $assignmentA->update(['individual_status' => 'rejected', 'acted_at' => now(), 'comments' => 'no']);
    app(WorkflowService::class)->completeStage($assignmentA->fresh(), 'rejected');

    // A lone reject on a 3-seat stage isn't a majority yet.
    expect($document->fresh()->global_status)->toBe('classified_validated');
});
