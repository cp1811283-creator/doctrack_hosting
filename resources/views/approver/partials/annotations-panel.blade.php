{{--
    "Review & Comment" — the document's plain extracted text (the same
    text every document already has stored, regardless of original file
    type — see TextExtractionService), with every open Request Revision
    annotation already highlighted, from any approver on any stage of
    this document. Select a new passage of text below to flag your own.

    Fetched by the shared openKpiDrilldown() modal (see components/
    kpi-drilldown-modal.blade.php) — same pattern already used for the
    SLA Violations approver popup, so no new modal component needed.

    Highlight rendering assumes non-overlapping annotation ranges (the
    common case — two approvers independently flagging the exact same
    characters is rare) — sorted by start_offset and walked sequentially
    below.

    No inline <script> here on purpose — this fragment is injected via
    body.innerHTML by openKpiDrilldown(), and browsers never execute
    <script> tags set that way. The interaction logic lives in queue.
    blade.php instead, delegated on the stable #kpi-drilldown-body
    ancestor (which survives every fragment swap), reading the URLs it
    needs from this root element's data attributes below.

    Presence icon + print button (Feature: parity with "View original
    file", which has both) — the polling that keeps the presence icon
    live is started/stopped from approver/dashboard.blade.php (see
    openReviewAndComment()/closeKpiDrilldown() there), the same
    <script>-doesn't-run-via-innerHTML reason as above. window.print()
    here works fine though — inline onclick="" attributes DO run on
    innerHTML-injected markup, only <script> tags don't.
--}}

@php
    // A real .docx renders through DocxRichContentService instead —
    // images, tables, and bold/italic labels, exactly as uploaded, with
    // the SAME start_offset/end_offset numbering (see that service's own
    // docblock on why that invariant is what lets every byte of this
    // feature — the "select a passage" JS, DocumentAnnotation, Request
    // Revision, Revision History — carry over unchanged instead of
    // needing a second, parallel highlighting scheme.
    if ($document->isRichDocx()) {
        $richAnnotations = $annotations->map(fn ($annotation) => [
            'start_offset' => $annotation->start_offset,
            'end_offset' => $annotation->end_offset,
            'tooltip' => e($annotation->raisedBy->full_name . ': ' . $annotation->comment),
        ])->all();

        $annotationTextHtml = app(\App\Services\DocxRichContentService::class)
            ->render(\Illuminate\Support\Facades\Storage::path($document->file_path), $richAnnotations)['html'];
    } else {
        // Built as a plain PHP string, not a Blade @foreach/@if loop in the
        // template body — the text content here has to be byte-for-byte
        // identical to $text (every offset the "select a passage" JS
        // computes has to line up with the offsets stored server-side
        // against $text), and Blade's own directive syntax turned out to be
        // too fragile for that: a more "readable" multi-line loop leaks its
        // own indentation/newlines into the output, and even a compact
        // single-line version silently fails to compile at all in one spot
        // (@endif immediately followed by @endforeach doesn't parse — this
        // shipped broken once already before either problem was found).
        // Plain string concatenation has neither failure mode.
        $text = $document->ocr_text ?? '';
        $cursor = 0;
        $annotationTextHtml = '';
        foreach ($annotations as $annotation) {
            if ($annotation->start_offset > $cursor) {
                $annotationTextHtml .= e(mb_substr($text, $cursor, $annotation->start_offset - $cursor));
            }
            $start = max($cursor, $annotation->start_offset);
            $highlighted = mb_substr($text, $start, $annotation->end_offset - $start);
            $tooltip = e($annotation->raisedBy->full_name . ': ' . $annotation->comment);
            $annotationTextHtml .= '<mark class="bg-processing-100 text-processing-900 rounded px-0.5 cursor-help" title="' . $tooltip . '">' . e($highlighted) . '</mark>';
            $cursor = max($cursor, $annotation->end_offset);
        }
        if ($cursor < mb_strlen($text)) {
            $annotationTextHtml .= e(mb_substr($text, $cursor));
        }
    }
@endphp
<div class="flex flex-col h-full"
    id="annotation-panel-root"
    data-request-revision-url="{{ route('approver.assignments.requestRevision', $assignment) }}"
    data-refresh-url="{{ route('approver.assignments.annotations', $assignment) }}">
    <div class="px-6 py-2.5 border-b border-surface-200 bg-surface-50/60 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <p class="text-sm text-surface-500">Select any passage below to flag it for revision.</p>
            <details id="annotation-presence" class="relative hidden flex-shrink-0">
                <summary class="list-none cursor-pointer flex items-center -space-x-1.5">
                    <span id="annotation-presence-avatars" class="flex items-center -space-x-1.5"></span>
                    <span class="ml-2 text-[11px] text-approved-700 font-medium flex items-center gap-1">
                        <span class="relative flex w-1.5 h-1.5">
                            <span class="absolute inline-flex h-full w-full rounded-full bg-approved-400 opacity-75 animate-ping"></span>
                            <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-approved-500"></span>
                        </span>
                        <span id="annotation-presence-count"></span> reviewing now
                    </span>
                </summary>
                <div id="annotation-presence-names" class="absolute z-10 mt-1 left-0 bg-white rounded-lg shadow-lg border border-surface-200 py-1.5 min-w-[200px]"></div>
            </details>
        </div>
        <div class="flex items-center gap-4 flex-shrink-0">
            @if($annotations->isNotEmpty())
                <p class="text-sm text-surface-400">{{ $annotations->count() }} open revision request{{ $annotations->count() === 1 ? '' : 's' }}</p>
            @endif
            {{-- Feature: Revision History — see documents/partials/
                 revision-history-list.blade.php. Reopens THIS SAME shared
                 modal on the history view instead (fetched-in markup, so
                 an inline onclick="" works here even though a <script>
                 tag wouldn't — see this file's own top-of-file comment). --}}
            <button type="button" onclick="openKpiDrilldown('revision-history', 'Revision History', {{ Js::from(route('documents.revisions', $document)) }})" class="text-surface-400 hover:text-surface-700" aria-label="View revision history" title="Revision History">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </button>
            <button type="button" onclick="__printClone(document.getElementById('annotation-text'))" class="text-surface-400 hover:text-surface-700" aria-label="Print document" title="Print">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.318 2.647a.75.75 0 01-.74.853H6.762a.75.75 0 01-.74-.853L6.34 18m11.32 0H6.34m10.94-9.75V4.243a.75.75 0 00-.75-.75H7.47a.75.75 0 00-.75.75V8.25m10.94 0H6.72"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Kept OUTSIDE #annotation-text on purpose — that element's text
         content has to stay byte-for-byte identical to $text (see the
         @php block's comment above), so nothing interactive can live
         inside it without corrupting every offset the "select a new
         passage" JS computes from its textContent. A "Withdraw" control
         only ever shows on the current approver's own still-open flag —
         see DocumentAnnotationPolicy::withdraw(), re-checked server-side
         regardless of what this markup offers. --}}
    @if($annotations->isNotEmpty())
        <div class="px-6 py-3 border-b border-surface-200 bg-surface-50/40 max-h-32 overflow-y-auto space-y-2 flex-shrink-0">
            @foreach($annotations as $annotation)
                <div class="flex items-start justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <span class="text-surface-800 italic">&ldquo;{{ \Illuminate\Support\Str::limit($annotation->selected_text, 80) }}&rdquo;</span>
                        <span class="block text-surface-500">{{ $annotation->raisedBy->full_name }}: {{ $annotation->comment }}</span>
                    </div>
                    @if($annotation->raised_by === auth()->id())
                        <button type="button"
                            data-withdraw-annotation
                            data-withdraw-url="{{ route('approver.annotations.withdraw', $annotation) }}"
                            class="text-xs font-medium text-rejected-700 hover:underline whitespace-nowrap flex-shrink-0">
                            Withdraw
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div id="annotation-text" data-document-id="{{ $document->document_id }}" class="flex-1 overflow-y-auto px-6 py-4 text-base text-surface-800 leading-relaxed" style="white-space: pre-wrap;">{!! $annotationTextHtml !!}</div>

    <div id="annotation-form-area" class="hidden border-t border-surface-200 bg-surface-50/60 px-6 py-4">
        <p class="text-sm text-surface-500 mb-2">Flagging: <span id="annotation-selected-preview" class="italic text-surface-700"></span></p>
        <form id="annotation-form" class="flex items-end gap-3">
            @csrf
            <textarea id="annotation-comment" rows="2" placeholder="What needs to change here?" required
                class="flex-1 rounded-lg border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
            <button type="submit" class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-semibold px-4 py-2.5 rounded-lg">Flag for Revision</button>
            <button type="button" id="annotation-cancel" class="text-sm font-medium text-surface-500 hover:underline px-2 py-2.5">Cancel</button>
        </form>
        <p id="annotation-status" class="text-sm mt-2"></p>
    </div>
</div>
