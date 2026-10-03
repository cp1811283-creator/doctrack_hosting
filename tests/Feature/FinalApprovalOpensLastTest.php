<?php

use App\Models\AdminViolation;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\SlaViolation;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\BusinessHoursService;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Carbon\Carbon;

/**
 * Final Approval is the Head Approver's sign-off, so it opens only once every
 * other stage is approved (WorkflowService::openFinalApprovalIfReady()), and
 * an earlier stage the system had to auto-approve is covered by that sign-off
 * rather than owing its own Admin review (settleDeferredAutoApprovalReviews()).
 * All times land on a Monday inside the 9-5 default business hours.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00')); // Monday

    $this->review = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    $this->final = WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
    $this->workflow = app(WorkflowService::class);
    $this->originator = User::factory()->originator()->create();
    $this->staff = User::factory()->approver('Job Order')->create(['level' => 'staff']);
    // The head is seated on Final Approval only, so Technical Review has exactly one seat.
    $this->head = User::factory()->approver('Job Order')->create(['level' => 'head']);
    $this->head->workflowStages()->sync([$this->final->stage_id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function routedJobOrder(?Carbon $dueDate = null): DocumentRepository
{
    $document = DocumentRepository::create([
        'originator_id' => test()->originator->user_id,
        'title' => 'job_order.txt',
        'file_path' => 'documents/job_order.txt',
        'mime_type' => 'text/plain',
        'ml_category' => 'Job Order',
        'is_validated' => true,
        'due_date' => $dueDate ?? now()->addDays(3),
        'global_status' => 'classified_validated',
    ]);
    test()->workflow->routeToWorkflow($document);

    return $document->fresh();
}

function seatOn(DocumentRepository $document, WorkflowStage $stage): ?DocumentAssignment
{
    return DocumentAssignment::where('document_id', $document->document_id)->where('stage_id', $stage->stage_id)->first();
}

it('does not open Final Approval while a review stage is still pending', function () {
    $document = routedJobOrder();

    expect(seatOn($document, $this->review))->not->toBeNull()
        ->and(seatOn($document, $this->final))->toBeNull();
});

it('opens Final Approval the moment the last review stage is approved, for the head only', function () {
    $document = routedJobOrder();
    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    $finalSeat = seatOn($document, $this->final);
    expect($finalSeat)->not->toBeNull()
        ->and($finalSeat->user_id)->toBe($this->head->user_id)
        ->and($finalSeat->individual_status)->toBe('pending')
        ->and($document->fresh()->global_status)->not->toBe('approved');
});

it('opens Final Approval after an auto-approved review stage too, since an auto-approval counts as approved', function () {
    $document = routedJobOrder();

    Carbon::setTestNow(now()->addHours(7)); // well past the review seat's window
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->review));

    expect(seatOn($document, $this->review)->auto_approved)->toBeTrue()
        ->and(SlaViolation::count())->toBe(1) // the approver who missed still gets the violation
        ->and(seatOn($document, $this->final)->individual_status)->toBe('pending');
});

it('never opens Final Approval once a review stage is rejected', function () {
    $document = routedJobOrder();
    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'rejected', 'wrong quantities');

    expect($document->fresh()->global_status)->toBe('rejected')
        ->and(seatOn($document, $this->final))->toBeNull();
});

it('gives Final Approval its own window measured from when it opens, not from routing time', function () {
    $document = routedJobOrder(now()->addDays(3));
    $routingWindow = seatOn($document, $this->review)->sla_expires_at->copy();

    Carbon::setTestNow(now()->addHours(2)); // the review stage took a while
    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    expect(seatOn($document, $this->final)->sla_expires_at->greaterThan($routingWindow))->toBeTrue()
        ->and(seatOn($document, $this->final)->sla_expires_at->greaterThan(now()))->toBeTrue();
});

it('guarantees Final Approval at least 15 working minutes even when the earlier stages finished after the due date', function () {
    $document = routedJobOrder(now()->addDays(3));
    $document->update(['due_date' => now()->addHours(1)]); // due 11:00

    Carbon::setTestNow(Carbon::parse('2026-09-14 11:10:00')); // review stage approved after the due date
    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    $minimum = app(BusinessHoursService::class)->addBusinessMinutes(now(), 15);
    expect(seatOn($document, $this->final)->sla_expires_at->equalTo($minimum))->toBeTrue();
});

it('covers an auto-approved review stage by the Head\'s sign-off — no Admin review is owed', function () {
    $document = routedJobOrder();
    Carbon::setTestNow(now()->addHours(7));
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->review));

    $reviewSeat = seatOn($document, $this->review)->fresh();
    // While the Head has yet to decide, the review is deferred — not owed, not late.
    expect($reviewSeat->review_due_at)->toBeNull()
        ->and(DocumentAssignment::awaitingAdminReview()->count())->toBe(0);

    $this->workflow->decide(seatOn($document, $this->final), $this->head, 'approved');

    $reviewSeat = $reviewSeat->fresh();
    expect($reviewSeat->admin_reviewed_at)->not->toBeNull()
        ->and($reviewSeat->admin_review_outcome)->toBe('covered')
        ->and($reviewSeat->admin_reviewed_by)->toBeNull()
        ->and(DocumentAssignment::awaitingAdminReview()->count())->toBe(0)
        ->and($document->fresh()->global_status)->toBe('approved')
        ->and($document->fresh()->display_status)->toBe('approved');

    // ...and no late-review violation can ever accrue for it.
    Carbon::setTestNow(now()->addHours(30));
    app(SlaService::class)->sweep();
    expect(AdminViolation::where('violation_type', 'late_review')->count())->toBe(0);
});

it('also covers it when the Head rejects — a human checked the document', function () {
    $document = routedJobOrder();
    Carbon::setTestNow(now()->addHours(7));
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->review));

    $this->workflow->decide(seatOn($document, $this->final), $this->head, 'rejected', 'scope is wrong');

    expect($document->fresh()->global_status)->toBe('rejected')
        ->and(seatOn($document, $this->review)->fresh()->admin_review_outcome)->toBe('covered');
});

it('does not start a late-review clock for an auto-approved review stage while the Head is still deciding', function () {
    $document = routedJobOrder();
    Carbon::setTestNow(now()->addHours(7));
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->review));

    Carbon::setTestNow(now()->addHours(5)); // longer than the 6-hour Admin review window
    app(SlaService::class)->sweep();

    expect(AdminViolation::where('violation_type', 'late_review')->count())->toBe(0);
});

it('reinstates the Admin review for earlier auto-approvals when the Head also fails to act', function () {
    $document = routedJobOrder();
    Carbon::setTestNow(now()->addHours(7));
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->review));

    Carbon::setTestNow(seatOn($document, $this->final)->sla_expires_at->copy()->addMinutes(10));
    app(SlaService::class)->autoApproveMissedDeadline(seatOn($document, $this->final));

    $reviewSeat = seatOn($document, $this->review)->fresh();
    expect($document->fresh()->global_status)->toBe('auto_approved')
        ->and($reviewSeat->review_due_at)->not->toBeNull()
        ->and($reviewSeat->admin_reviewed_at)->toBeNull()
        ->and(DocumentAssignment::awaitingAdminReview()->pluck('assignment_id')->sort()->values()->all())
        ->toBe(collect([$reviewSeat->assignment_id, seatOn($document, $this->final)->assignment_id])->sort()->values()->all());
});

it('auto-approves Final Approval on the spot when no Head is eligible, and that seat is owed an Admin review', function () {
    $this->head->update(['is_active' => false]);
    $document = routedJobOrder();

    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    $finalSeat = seatOn($document, $this->final);
    expect($finalSeat->auto_approved)->toBeTrue()
        ->and($finalSeat->review_due_at)->not->toBeNull()
        ->and($document->fresh()->global_status)->toBe('auto_approved')
        ->and(DocumentAssignment::awaitingAdminReview()->count())->toBe(1);
});

it('still owes an Admin review for an auto-approved stage of a category with no Final Approval stage at all', function () {
    WorkflowStage::create(['document_category' => 'Service Report', 'stage_name' => 'Quality Inspection', 'sequence_order' => 1]);
    $inspector = User::factory()->approver('Service Report')->create();
    $document = DocumentRepository::create([
        'originator_id' => $this->originator->user_id, 'title' => 'sr.txt', 'file_path' => 'documents/sr.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Service Report', 'is_validated' => true,
        'due_date' => now()->addDays(3), 'global_status' => 'classified_validated',
    ]);
    $this->workflow->routeToWorkflow($document);

    Carbon::setTestNow(now()->addHours(7));
    app(SlaService::class)->autoApproveMissedDeadline(DocumentAssignment::where('document_id', $document->document_id)->first());

    $seat = DocumentAssignment::where('document_id', $document->document_id)->first();
    expect($seat->review_due_at)->not->toBeNull()
        ->and($inspector->user_id)->toBe($seat->user_id)
        ->and(DocumentAssignment::awaitingAdminReview()->count())->toBe(1);
});

it('routes a Final Approval-only category immediately, since there is nothing to wait for', function () {
    WorkflowStage::create(['document_category' => 'Purchase Requisition', 'stage_name' => 'Final Approval', 'sequence_order' => 1]);
    User::factory()->approver('Purchase Requisition')->create(['level' => 'head']);
    $document = DocumentRepository::create([
        'originator_id' => $this->originator->user_id, 'title' => 'pr.txt', 'file_path' => 'documents/pr.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Purchase Requisition', 'is_validated' => true,
        'due_date' => now()->addDays(3), 'global_status' => 'classified_validated',
    ]);
    $this->workflow->routeToWorkflow($document);

    // 2, not 1 — this file's own beforeEach() head ($this->head) has no
    // department set, and this stage has no department requirement either,
    // so now that a head's Final Approval eligibility is category-agnostic
    // (see WorkflowService::eligibleApproversForStage()'s docblock), that
    // head is also legitimately eligible here, alongside the one created
    // specifically for Purchase Requisition above.
    expect(DocumentAssignment::where('document_id', $document->document_id)->where('individual_status', 'pending')->count())->toBe(2);
});

it('never opens the category\'s Final Approval for a custom-routed document', function () {
    $document = DocumentRepository::create([
        'originator_id' => $this->originator->user_id, 'title' => 'custom.txt', 'file_path' => 'documents/custom.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addDay(), 'global_status' => 'classified_validated',
        'desired_routing' => 'custom', 'pending_custom_routing_at' => now(),
    ]);
    $this->workflow->routeToCustomApprovers($document, [$this->staff->user_id], $this->originator);

    $customSeat = DocumentAssignment::where('document_id', $document->document_id)->first();
    $this->workflow->decide($customSeat, $this->staff, 'approved');

    expect($document->fresh()->global_status)->toBe('approved')
        ->and(DocumentAssignment::where('document_id', $document->document_id)->count())->toBe(1);
});

it('leaves a document routed before this rule untouched — its up-front Final Approval seat is not duplicated', function () {
    $document = routedJobOrder();
    // Simulate the old behavior: the Final Approval seat was created at routing time.
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $this->head->user_id, 'stage_id' => $this->final->stage_id,
        'due_date' => $document->due_date, 'priority_rank' => 2, 'individual_status' => 'pending',
        'sla_expires_at' => seatOn($document, $this->review)->sla_expires_at,
    ]);

    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    expect(DocumentAssignment::where('document_id', $document->document_id)->where('stage_id', $this->final->stage_id)->count())->toBe(1);
});

it('tells the originator Final Approval opens after every other stage, until it does', function () {
    $document = routedJobOrder();

    $this->actingAs($this->originator)->get(route('originator.documents.show', $document))
        ->assertOk()
        ->assertSee('Opens after every other stage is approved');

    $this->workflow->decide(seatOn($document, $this->review), $this->staff, 'approved');

    $this->actingAs($this->originator)->get(route('originator.documents.show', $document->fresh()))
        ->assertOk()
        ->assertDontSee('Opens after every other stage is approved');
});
