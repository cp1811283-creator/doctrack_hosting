<?php

use App\Events\DocumentStatusChanged;
use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Final Approval', 'sequence_order' => 2]);
});

function trackedDocument(User $originator): DocumentRepository
{
    return DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'doc.txt', 'file_path' => 'documents/doc.txt',
        'mime_type' => 'text/plain', 'ml_category' => 'Job Order', 'is_validated' => true,
        'due_date' => now()->addHours(2), 'global_status' => 'classified_validated',
    ]);
}

it('broadcasts DocumentStatusChanged to the owning originator, every admin, AND every approver', function () {
    // The 'approvers' channel is what lets the Archive page (any approver
    // can browse it) live-refresh when a document newly arrives there —
    // regression coverage for that broadcast reaching the right audience.
    $originator = User::factory()->originator()->create();
    $document = trackedDocument($originator);

    $channels = (new DocumentStatusChanged($document))->broadcastOn();
    $channelNames = array_map(fn ($c) => $c->name, $channels);

    expect($channelNames)->toContain('private-originator.'.$originator->user_id)
        ->toContain('private-admin-dashboard')
        ->toContain('private-approvers');
});

it('broadcasts DocumentStatusChanged when a single assignment is decided, even if global_status has not finalized yet', function () {
    Event::fake([DocumentStatusChanged::class]);

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = trackedDocument($originator);
    $stageOne = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $stageTwo = WorkflowStage::where('stage_name', 'Final Approval')->first();

    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageTwo->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'auto_approved' => false,
    ]);
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stageOne->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'auto_approved' => false,
    ]);

    // Decide only ONE of the two stages — global_status should stay
    // 'classified_validated' (a second stage is still pending), but the
    // event must still fire so the tracking page's Approval Stages list
    // updates immediately.
    $assignment->individual_status = 'approved';
    $assignment->save();

    Event::assertDispatched(DocumentStatusChanged::class, fn ($e) => $e->document->document_id === $document->document_id);
    expect($document->fresh()->global_status)->toBe('classified_validated');
});

it('does not broadcast when an unrelated assignment field changes', function () {
    Event::fake([DocumentStatusChanged::class]);

    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = trackedDocument($originator);
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    $assignment = DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'pending', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'auto_approved' => false,
    ]);

    Event::fake([DocumentStatusChanged::class]); // clear the AssignmentRouted-triggered baseline
    $assignment->comments = 'just a note, no status change';
    $assignment->save();

    Event::assertNotDispatched(DocumentStatusChanged::class);
});

it('renders the tracking fragment reflecting an assignment decision', function () {
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    $document = trackedDocument($originator);
    $stage = WorkflowStage::where('stage_name', 'Technical Review')->first();
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'stage_id' => $stage->stage_id, 'user_id' => $approver->user_id,
        'individual_status' => 'approved', 'sla_expires_at' => now()->addHour(), 'priority_rank' => 1,
        'auto_approved' => false, 'acted_at' => now(),
    ]);

    $response = $this->actingAs($originator)->get(route('originator.documents.trackingRefresh', $document));

    $response->assertOk()->assertSee('Approval Stages');
});

it('reports the current status and assignment states via trackingPoll', function () {
    $originator = User::factory()->originator()->create();
    $document = trackedDocument($originator);

    $response = $this->actingAs($originator)->getJson(route('originator.documents.trackingPoll', $document));

    $response->assertOk()->assertJson(['status' => 'classified_validated']);
});

it('subscribes an Admin viewer to admin-dashboard, so the tracker is instant for them too, not poll-only', function () {
    // Regression coverage: DocumentStatusChanged was always broadcast on
    // 'admin-dashboard' (see the first test in this file), but the
    // tracking page's own script only ever subscribed to
    // 'originator.{ownerId}' — a channel Admin can never pass the auth
    // check for (routes/channels.php requires isOriginator()). An Admin
    // viewing someone else's document was silently stuck on the ~45-75s
    // poll fallback only, with no visible error, which is what made this
    // page read as "not realtime" for Admin specifically.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = trackedDocument($originator);

    $asAdmin = $this->actingAs($admin)->get(route('documents.track', $document));
    $asOriginator = $this->actingAs($originator)->get(route('documents.track', $document));

    $asAdmin->assertOk()->assertSee("startLiveChannel('admin-dashboard', '.document.status-changed', opts);", false);
    $asOriginator->assertOk()->assertDontSee("startLiveChannel('admin-dashboard', '.document.status-changed', opts);", false);
});

it('rejects tracking poll/refresh for a document that is not the requester\'s own', function () {
    $owner = User::factory()->originator()->create();
    $someoneElse = User::factory()->originator()->create();
    $document = trackedDocument($owner);

    $this->actingAs($someoneElse)->getJson(route('originator.documents.trackingPoll', $document))->assertForbidden();
    $this->actingAs($someoneElse)->get(route('originator.documents.trackingRefresh', $document))->assertForbidden();
});
