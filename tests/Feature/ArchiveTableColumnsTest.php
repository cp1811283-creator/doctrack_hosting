<?php

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * upload_date isn't in DocumentRepository::$fillable (populated by the
 * ingest pipeline, not meant to be set directly), and updated_at is
 * Eloquent-managed regardless of fillable — create()/save() silently
 * overwrite both with "now". Forcing exact values via the query builder
 * directly, bypassing Eloquent, is the only way to backdate them in a test.
 */
function forceTimestamps(DocumentRepository $document, array $values): DocumentRepository
{
    DB::table('document_repository')->where('document_id', $document->document_id)->update($values);

    return $document->fresh();
}

/**
 * Coverage for the Archive table's Uploaded/Approved/Due Date columns and
 * the new "View" button — added per user feedback asking for upload/due
 * date visibility and an in-app preview before downloading.
 */
it('shows Uploaded, Approved, and Due Date as full date-and-time values', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'columns-test.txt', 'file_path' => 'documents/columns-test.txt',
        'mime_type' => 'text/plain', 'due_date' => '2026-08-05 17:00:00',
        'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);
    forceTimestamps($document, ['upload_date' => '2026-08-01 09:15:00']);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Aug 1, 2026, 9:15 AM'); // Uploaded
    $response->assertSee('Aug 5, 2026, 5:00 PM'); // Due Date
});

it('sources the Approved column from the last approved assignment, not the row\'s updated_at', function () {
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Review', 'sequence_order' => 1]);

    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'approved-col-test.txt', 'file_path' => 'documents/approved-col-test.txt',
        'mime_type' => 'text/plain', 'upload_date' => now(), 'due_date' => now()->addDay(),
        'global_status' => 'auto_approved', 'ml_category' => 'Job Order',
    ]);
    DocumentAssignment::create([
        'document_id' => $document->document_id, 'user_id' => $approver->user_id,
        'stage_id' => WorkflowStage::first()->stage_id, 'due_date' => $document->due_date,
        'priority_rank' => 2, 'individual_status' => 'auto_approved', 'auto_approved' => true,
        'acted_at' => '2026-08-02 14:30:00',
    ]);

    // Simulates a later, unrelated dispute — touches the row's updated_at
    // WITHOUT a new approval ever happening (see
    // AdminController::reviewAutoApproval()'s dispute path). The Approved
    // column must still show the real approval date, not this later one.
    $document = forceTimestamps($document, ['disputed_at' => '2026-08-09 08:00:00', 'updated_at' => '2026-08-09 08:00:00']);
    expect($document->updated_at->format('Y-m-d'))->toBe('2026-08-09');

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Aug 2, 2026, 2:30 PM'); // real approval date
    $response->assertDontSee('Aug 9, 2026, 8:00 AM'); // dispute date must not appear as "Approved"
});

it('falls back to updated_at for the Approved column on a legacy import with no assignments', function () {
    $admin = User::factory()->admin()->create();
    $document = DocumentRepository::create([
        'originator_id' => $admin->user_id, 'title' => 'legacy-col-test.txt', 'file_path' => 'documents/legacy-col-test.txt',
        'mime_type' => 'text/plain', 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order', 'is_legacy_import' => true,
    ]);
    forceTimestamps($document, ['updated_at' => '2026-08-04 11:00:00']);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('Aug 4, 2026, 11:00 AM');
});

it('shows an Auto-Approved badge for a document nobody actually decided, and none for a genuinely human-approved one', function () {
    // Regression coverage for a real reported bug (confirmed 2026-10-03):
    // Archive had an "Imported" badge and a "Disputed" badge, but nothing
    // distinguishing an auto-approved document from a normally human-
    // approved one — once something lands in Archive there was no way to
    // tell its provenance at a glance. Checks global_status directly (not
    // display_status, which upgrades the DISPLAYED label to "Approved"
    // once reviewed but never touches the underlying column) — this badge
    // is about whether a human ever actually decided it, which review
    // status doesn't change.
    $admin = User::factory()->admin()->create();
    $originator = User::factory()->originator()->create();

    $autoApproved = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'auto-approved-archive-test.txt', 'file_path' => 'documents/aa.txt',
        'mime_type' => 'text/plain', 'upload_date' => now(), 'due_date' => now()->addDay(),
        'global_status' => 'auto_approved', 'ml_category' => 'Job Order',
    ]);
    $humanApproved = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'human-approved-archive-test.txt', 'file_path' => 'documents/ha.txt',
        'mime_type' => 'text/plain', 'upload_date' => now(), 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.archive', ['category' => 'Job Order']));
    $response->assertOk();
    $html = $response->getContent();

    // Checked independently of row order (not relative to each other) —
    // both documents share an identical upload_date, so which row the
    // query happens to list first isn't something this test should rely on.
    $autoRow = Str::before(Str::after($html, 'auto-approved-archive-test.txt'), '</tr>');
    $humanRow = Str::before(Str::after($html, 'human-approved-archive-test.txt'), '</tr>');

    expect($autoRow)->toContain('Auto-Approved')
        ->and($humanRow)->not->toContain('Auto-Approved');
});

it('shows a View button for every archived document regardless of print-required or role, opening the viewer without autoPrint', function () {
    $originator = User::factory()->originator()->create();
    $document = DocumentRepository::create([
        'originator_id' => $originator->user_id, 'title' => 'view-btn-test.txt', 'file_path' => 'documents/view-btn-test.txt',
        'mime_type' => 'text/plain', 'upload_date' => now(), 'due_date' => now()->addDay(),
        'global_status' => 'approved', 'ml_category' => 'Job Order', 'requires_printing' => false,
    ]);

    $response = $this->actingAs($originator)->get(route('originator.archive', ['category' => 'Job Order']));

    $response->assertOk();
    $response->assertSee('View');
    $response->assertSee("openDocumentViewer('".route('documents.file', $document)."', 'text/plain', 'view-btn-test.txt', {$document->document_id}, false)", false);
});
