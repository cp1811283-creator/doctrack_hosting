<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentRepository;
use App\Rules\ReliableMimeType;
use App\Services\TextExtractionService;
use App\Services\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * ArchiveController
 * -------------------
 * Implements the "Centralized Digital Records Repository" (Scope 1.4) and
 * DFD Process 8.0 (Search & Retrieval): a searchable list of approved
 * documents, filterable by keyword/category/date, with download.
 *
 * Access rule (per product decision — three distinct views by role):
 *   - Admin sees every category, unrestricted, and can additionally import a
 *     pre-existing, already-approved legacy document straight into the
 *     repository (bypassing classification/validation/workflow entirely).
 *   - Approver sees only the approved documents they were assigned to during
 *     the approval process (any category, including Other), so every
 *     document they decided on stays retrievable after it is approved.
 *     Heads and staff follow the same rule. Category does not limit access.
 *   - Originator is NOT scoped by category at all — an originator can
 *     upload any kind of document (the ML classifier determines its
 *     category automatically per upload), so tying their account to one
 *     category would be wrong. Instead, their Archive shows only THEIR OWN
 *     approved submissions, across every category, with an optional
 *     category filter for narrowing the search.
 */
class ArchiveController extends Controller
{
    /**
     * A pseudo-category, not a real trained one — the folder/filter value
     * for a document the originator flagged as not belonging to any
     * current category (Feature: originator-directed routing — see
     * DocumentRepository::desired_routing). The ML classifier is closed-
     * set, so ml_category is ALWAYS one of the real known categories even
     * for one of these (the classifier's best guess is kept for
     * reference — see ValidationService::validateGeneric()'s docblock),
     * which is why this folder has to be identified via desired_routing
     * rather than filtered by ml_category's actual value.
     */
    private const OTHER_FOLDER = 'Other';

    public function __construct(private TextExtractionService $extractor) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Staff approvers go straight to the flat results list of their
        // assigned documents. Admin, Originator, and Head approvers see a
        // folder grid first (Feature: browse by category), UNLESS they've
        // already got a search/filter active — that's what "search
        // everything from the folder screen" (below) transitions into.
        $hasActiveFilters = $request->filled('category') || $request->filled('keyword')
            || $request->filled('date_from') || $request->filled('date_to');
        $showFolders = $this->browsesByFolder($user) && ! $hasActiveFilters;

        if ($showFolders) {
            return view('archive.index', [
                'showFolders' => true,
                'folders' => $this->folderStats($user),
                'restrictedCategory' => null,
                'isOwnSubmissionsView' => $user->isOriginator(),
            ]);
        }

        [$documents, $isOwnSubmissionsView] = $this->searchResults($request, $user);

        return view('archive.index', [
            'showFolders' => false,
            'documents' => $documents,
            'restrictedCategory' => null,
            'isOwnSubmissionsView' => $isOwnSubmissionsView,
        ]);
    }

    /**
     * Admin, Originator, and Head approvers can use the category folders
     * and the category filter. Staff approvers cannot: their archive is
     * always a flat list of the documents assigned to them.
     */
    private function browsesByFolder($user): bool
    {
        return ! $user->isApprover() || $user->isHead();
    }

    /**
     * Live search (Feature: instant results as you type) — same query as
     * index()'s results branch, via the shared searchResults() below, but
     * returns just the results-table fragment for the front-end to swap
     * in, instead of the whole page.
     */
    public function refresh(Request $request)
    {
        $user = $request->user();

        [$documents, $isOwnSubmissionsView] = $this->searchResults($request, $user);

        return view('archive.partials.results', compact('documents', 'isOwnSubmissionsView'));
    }

    /**
     * @return array{0: Collection<int, DocumentRepository>, 1: bool}
     */
    private function searchResults(Request $request, $user): array
    {
        $query = DocumentRepository::query()
            ->whereIn('global_status', ['approved', 'auto_approved'])
            ->with('originator', 'assignments');

        $isOwnSubmissionsView = false;

        if ($user->isApprover()) {
            // Hard RBAC restriction — approvers cannot override this via input.
            $query->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $user->user_id));
        } elseif (! $user->isAdmin()) {
            // Originator: their own approved submissions, any category —
            // they may still narrow it down with the category filter below.
            $query->where('originator_id', $user->user_id);
            $isOwnSubmissionsView = true;
        }

        // Category filter is available to Admin (any category) and
        // Originator (within their own submissions); Approver's category is
        // fixed above and not user-selectable. self::OTHER_FOLDER is
        // a pseudo-category, not a real one the classifier ever assigns —
        // see folderStats()'s docblock for why it has to be checked via
        // desired_routing rather than ml_category (the classifier is
        // closed-set, so ml_category is ALWAYS one of the real known
        // categories even for a document flagged this way).
        if ($this->browsesByFolder($user) && $request->filled('category')) {
            $category = $request->string('category');
            $category->toString() === self::OTHER_FOLDER
                ? $query->where('desired_routing', 'unrelated')
                : $query->where('ml_category', $category);
        }

        if ($request->filled('keyword')) {
            $keyword = $request->string('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('ocr_text', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('upload_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('upload_date', '<=', $request->date('date_to'));
        }

        match ($request->string('sort')->toString()) {
            'oldest' => $query->oldest('upload_date'),
            'originator' => $query->join('users', 'document_repository.originator_id', '=', 'users.user_id')
                ->orderBy('users.full_name')
                ->select('document_repository.*'),
            default => $query->latest('upload_date'),
        };

        // Feature: client-side row fitting — see resources/js/app.js's
        // initFittedPagination() and AdminController::documents()'s
        // matching docblock. Every matching document is sent in one
        // response; the browser measures the whole list and works out
        // every page's real boundary itself, instead of a guessed fixed
        // page size that either wasted screen space or needed its own
        // internal scrollbar.
        return [$query->get(), $isOwnSubmissionsView];
    }

    /**
     * One row per category for the folder-grid landing screen — total
     * count plus a status breakdown (plain approved/auto-approved vs.
     * disputed) so an Admin/Originator can see at a glance whether a
     * category has anything flagged before even opening it. Scoped to
     * "my own submissions only" for an Originator, same restriction the
     * results view already applies — see index() above.
     */
    private function folderStats($user)
    {
        $base = DocumentRepository::query()->whereIn('global_status', ['approved', 'auto_approved']);

        if ($user->isApprover()) {
            $base->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $user->user_id));
        } elseif (! $user->isAdmin()) {
            $base->where('originator_id', $user->user_id);
        }

        $folders = collect(ValidationService::knownCategories())->map(function ($category) use ($base) {
            $categoryQuery = (clone $base)->where('ml_category', $category);

            return (object) [
                'category' => $category,
                'total' => (clone $categoryQuery)->count(),
                'disputed' => (clone $categoryQuery)->whereNotNull('disputed_at')->count(),
                'auto_approved' => (clone $categoryQuery)->where('global_status', 'auto_approved')->whereNull('disputed_at')->count(),
            ];
        });

        // Always shown, same as the real categories above (even at 0) —
        // for discoverability: hiding it until the first "unrelated"
        // document actually cleared approval made it look like the folder
        // didn't exist at all in the meantime, when originators uploading
        // that kind of document is a normal, expected occurrence.
        $otherQuery = (clone $base)->where('desired_routing', 'unrelated');
        $folders->push((object) [
            'category' => self::OTHER_FOLDER,
            'total' => (clone $otherQuery)->count(),
            'disputed' => (clone $otherQuery)->whereNotNull('disputed_at')->count(),
            'auto_approved' => (clone $otherQuery)->where('global_status', 'auto_approved')->whereNull('disputed_at')->count(),
        ]);

        return $folders;
    }

    public function download(Request $request, DocumentRepository $document)
    {
        $user = $request->user();

        abort_unless(in_array($document->global_status, ['approved', 'auto_approved']), 404);

        if ($user->isApprover()) {
            // Re-check on the individual document, not just at list time —
            // prevents an approver from downloading via a guessed URL.
            abort_unless(
                $document->assignments()->where('user_id', $user->user_id)->exists(),
                403,
                'You can only download documents assigned to you.'
            );
        } elseif (! $user->isAdmin()) {
            // Originator: can only download documents they themselves submitted.
            abort_unless(
                $document->originator_id === $user->user_id,
                403,
                'You can only download your own submissions.'
            );
        }

        // Default disk (config('filesystems.default')), not hardcoded
        // 'local' — respects FILESYSTEM_DISK, so this still works when
        // that's S3-compatible object storage (e.g. Cloudflare R2) rather
        // than the local disk, which doesn't survive a Railway redeploy.
        abort_unless(Storage::exists($document->file_path), 404, 'File no longer available on disk.');

        AuditLog::record($user->user_id, $document->document_id, 'archive_download',
            "{$user->full_name} downloaded '{$document->title}' from the archive.");

        // A .docx is kept surgically in sync with any approved revision
        // (see WorkflowService::saveDocxRevision()) — the stored file
        // genuinely is the current document. Every other type only ever
        // had its extracted text revised (see DocumentController::
        // viewFile()'s matching comment), so downloading the raw file
        // here would hand out a stale, un-revised copy — the text IS the
        // document for these types now.
        if (! $document->isRichDocx()) {
            return response($document->ocr_text ?? '', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.($document->original_filename ?? $document->title).'"',
            ]);
        }

        return Storage::download($document->file_path, $document->original_filename ?? $document->title);
    }

    /**
     * Feature: the "Import Legacy Document" popup — fetched into
     * components/kpi-drilldown-modal.blade.php by the "+ Import Legacy
     * Document" button in archive/partials/results.blade.php's header.
     * No data needed beyond the category list the form itself already
     * pulls from ValidationService::knownCategories() — this is just the
     * form's own markup, split out of the page so it isn't sitting hidden
     * in every archive page's HTML on every load.
     */
    public function legacyForm()
    {
        return view('admin.partials.legacy-import-form');
    }

    /**
     * Admin-only: import a pre-existing, already-approved legacy document
     * directly into the repository. Skips the classification/validation/
     * workflow pipeline entirely since the document is already approved.
     */
    public function storeLegacy(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType, 'max:20480'],
            'category' => ['required', 'in:'.implode(',', ValidationService::knownCategories())],
            'title' => ['nullable', 'string', 'max:255'],
            // Required, not optional — this is the only record of WHY a
            // document skipped classification/validation/peer review
            // entirely. A bare "admin X imported this" audit line doesn't
            // tell a future auditor whether that was legitimate (digitizing
            // old paperwork) or something worth questioning.
            'import_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $file = $validated['file'];
        // Default disk, not hardcoded 'local' — see WorkflowService::ingest()'s matching comment.
        $storedPath = $file->store('documents');
        $extraction = $this->extractor->extract($file); // populates ocr_text so it stays searchable

        $document = DocumentRepository::create([
            'originator_id' => $request->user()->user_id, // attributed to the importing admin
            'title' => ($validated['title'] ?? null) ?: $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'ocr_text' => $extraction['text'],
            'used_ocr_fallback' => $extraction['used_ocr_fallback'],
            'ml_category' => $validated['category'],
            'ml_confidence' => null,
            'model_id' => null,
            'is_validated' => true,
            'validation_errors' => null,
            'global_status' => 'approved',
            'is_legacy_import' => true,
        ]);

        AuditLog::record($request->user()->user_id, $document->document_id, 'legacy_import',
            "Admin {$request->user()->full_name} imported pre-existing approved document '{$document->title}' (category: {$validated['category']}) directly into the archive, bypassing classification/validation/approval. Reason: \"{$validated['import_reason']}\"");

        return back()->with('status', "'{$document->title}' added to the archive.");
    }
}
