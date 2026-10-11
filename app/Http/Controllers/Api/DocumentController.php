<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\DocumentRepository;
use App\Models\SubmissionBatch;
use App\Rules\ReliableMimeType;
use App\Services\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * JSON equivalent of the originator dashboard/upload flow
 * (App\Http\Controllers\DocumentController) — same validation rules and
 * the same WorkflowService::ingest() pipeline (text extraction, ML
 * classification, validation, routing), just returning JSON instead of a
 * redirect + flashed Blade view. Kept as a separate controller rather than
 * branching the web one on Accept-header, so each stays a plain, readable
 * mapping of one request shape to one response shape.
 */
class DocumentController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    /** The authenticated originator's own submissions; Admin sees every document. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->isAdmin()
            ? DocumentRepository::query()
            : DocumentRepository::forOriginator($user->user_id);

        if ($request->filled('status')) {
            $query->where('global_status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('ml_category', $request->string('category'));
        }

        $documents = $query->with('originator')->latest('upload_date')->paginate(20);

        return DocumentResource::collection($documents);
    }

    public function show(Request $request, DocumentRepository $document)
    {
        $this->authorize('viewTracking', $document);

        $document->load(['assignments.stage', 'originator']);

        return new DocumentResource($document);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isOriginator(), 403, 'Only originator accounts can submit documents.');

        $validated = $request->validate([
            // Same 15MB x 10 files = 150MB rules as the web originator
            // upload flow (App\Http\Controllers\DocumentController::store())
            // — see that method's own docblock for why these specific
            // numbers were chosen (deliberately reconciled against the
            // real total ceiling, not two independent numbers that
            // happened to multiply out to more than the server accepts).
            'files' => ['required', 'array', 'min:1', 'max:10', function ($attribute, $value, $fail) {
                $totalBytes = collect($value)->filter()->sum(fn ($file) => $file->getSize());
                $limitBytes = 150 * 1024 * 1024;
                if ($totalBytes > $limitBytes) {
                    $totalMb = round($totalBytes / (1024 * 1024), 1);
                    $fail("These files add up to {$totalMb} MB, which is over the 150 MB total-upload limit — try fewer files or a smaller batch.");
                }
            }],
            'files.*' => ['file', 'mimes:pdf,docx,doc,txt,png,jpg,jpeg', new ReliableMimeType, 'max:15360'],
            'due_date' => ['required', 'date', function ($attribute, $value, $fail) {
                $buffer = config('sla.min_due_date_buffer_minutes', 15);
                if (Carbon::parse($value)->lt(now()->addMinutes($buffer))) {
                    $fail("The due date must be at least {$buffer} minutes from now.");

                    return;
                }
                if (! $this->workflow->isDueDateWithinWorkingHours($value)) {
                    $fail('The due date must fall within working hours (9 AM–5 PM, Mon–Sat, excluding holidays). Please pick a valid date and time.');
                }
            }],
        ]);

        $effectiveDueDate = Carbon::parse($validated['due_date']);

        $batch = SubmissionBatch::create([
            'originator_id' => $request->user()->user_id,
            'due_date' => $effectiveDueDate,
        ]);

        $documents = collect($validated['files'])
            ->map(fn ($file) => $this->workflow->ingest($file, $request->user(), $effectiveDueDate->toDateTimeString(), $batch->batch_id));

        return DocumentResource::collection($documents)->response()->setStatusCode(201);
    }
}
