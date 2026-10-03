{{--
    Extracted from archive/index.blade.php so ArchiveController::refresh()
    can return exactly this fragment for the live-search JS to swap in,
    without re-rendering the whole page (filter bar, sidebar, layout).
    Expects: $documents (a plain Collection — see initFittedPagination()
    in archive/index.blade.php for why this is no longer a real
    LengthAwarePaginator), $isOwnSubmissionsView.
--}}
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between flex-shrink-0">
        <div class="flex items-baseline gap-2">
            <h2 class="text-sm font-semibold text-surface-900">Approved Documents</h2>
            <span class="text-xs text-surface-400 tabular-nums">{{ $documents->count() }} total</span>
        </div>
        @if(auth()->user()->isAdmin())
            {{-- Feature: "+ Import Legacy Document" as a button + popup
                 instead of a permanently-visible collapsible bar (see
                 archive/index.blade.php's matching comment) — same
                 placement/style as the Originator dashboard's
                 "+ New Submission" button. --}}
            <button type="button"
                onclick="openKpiDrilldown('legacy-import', 'Import Legacy Document', '{{ route('admin.archive.legacy.form') }}')"
                class="inline-flex items-center gap-2 bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-sm transition-all">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Import Legacy Document
            </button>
        @endif
    </div>
    @if($isOwnSubmissionsView)
        <p class="px-6 pt-3 text-xs text-surface-400">Showing only documents you submitted, across all categories.</p>
    @endif
    {{-- flex-1 + overflow-hidden here (not on the pagination div below) —
         the parent #archive-results is capped to the device's screen
         height (see archive/index.blade.php); this is what
         resources/js/app.js's initFittedPagination() measures against to
         decide how many rows actually fit, hiding the rest. table-fixed +
         explicit widths (same reasoning as admin/partials/audit-results.
         blade.php, fixed 2026-10-01) — table-auto would let column widths
         depend on however many rows the fitting measurement pass happens
         to unhide at once, undercounting badly once there's enough
         archived documents for that to matter. --}}
    <div class="overflow-x-auto flex-1 overflow-y-hidden js-adaptive-rows-area">
    <table class="w-full min-w-[720px] text-sm table-fixed">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-4 py-3 font-medium w-[22%]">Document</th>
                <th class="text-left px-4 py-3 font-medium w-[12%]">Category</th>
                <th class="text-left px-4 py-3 font-medium w-[12%]">Originator</th>
                <th class="text-left px-4 py-3 font-medium w-[15%]">Uploaded</th>
                <th class="text-left px-4 py-3 font-medium w-[15%]">Approved</th>
                <th class="text-left px-4 py-3 font-medium w-[15%]">Due Date</th>
                <th class="px-4 py-3 w-[9%]"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                @php
                    // Legacy-imported documents (see ArchiveController::storeLegacy())
                    // never get real DocumentAssignment rows — they skip the
                    // workflow entirely — so there's no "last approver" to
                    // anchor on; falls back to the row's own updated_at for
                    // those specifically.
                    $approvedAt = $doc->assignments
                        ->whereIn('individual_status', ['approved', 'auto_approved'])
                        ->max('acted_at') ?? $doc->updated_at;
                @endphp
                {{-- data-row-group gives initFittedPagination() a distinct
                     fitting group per row (see admin/partials/audit-row.
                     blade.php for the identical convention). --}}
                <tr class="archive-row hover:bg-surface-50 transition-colors cursor-pointer" data-row-group="{{ $doc->document_id }}"
                    onclick="openKpiDrilldown('document-tracker', '{{ addslashes($doc->title) }}', '{{ route('documents.trackerModal', $doc) }}')">
                    {{-- flex + min-w-0, not max-w-xs+truncate on the whole
                         cell (confirmed real bug, 2026-10-03: a long title
                         filled that cell's truncated width entirely and
                         silently clipped every badge after it out of view —
                         present in the DOM, just never actually visible).
                         Title gets its OWN truncating span (min-w-0 lets it
                         actually shrink inside a flex row instead of
                         forcing the row wider); badges sit outside it as
                         flex-shrink-0 siblings, so they're never the thing
                         that gets sacrificed for space. --}}
                    <td class="px-4 py-3 font-medium text-surface-800 max-w-xs">
                        <div class="flex items-center gap-1.5 min-w-0">
                            {{-- Feature: opens the shared Document Tracker
                                 popup — see admin/partials/audit-row.blade.php's
                                 matching comment for why a popup instead of
                                 expanding this row in place. --}}
                            <span class="truncate min-w-0" title="{{ $doc->title }}">
                                <svg class="inline-block w-3 h-3 mr-1 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5a3 3 0 013-3h9a3 3 0 013 3v15a3 3 0 01-3 3h-9a3 3 0 01-3-3v-15z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 8.25h7.5M8.25 12h7.5M8.25 15.75h4.5"/></svg>
                                {{ $doc->title }}
                            </span>
                            @if($doc->is_legacy_import)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 flex-shrink-0">Imported</span>
                            @endif
                            {{-- global_status (not display_status) —
                                 deliberately stays 'auto_approved' forever
                                 even once Admin reviews it (see
                                 DocumentRepository::display_status's
                                 accessor, which only upgrades the DISPLAYED
                                 label to "Approved" once reviewed, never the
                                 underlying column) — this badge is about
                                 provenance (a human never actually decided
                                 this one), which review status doesn't
                                 change. Previously missing entirely: Archive
                                 gave no way to tell an auto-approved
                                 document apart from a normally human-
                                 approved one. Amber (processing), not
                                 green — matches the same "Auto-Approved —
                                 Pending Review" badge color used everywhere
                                 else this status shows, so it reads as
                                 visually distinct from a genuinely human-
                                 approved document, not just textually. --}}
                            @if($doc->global_status === 'auto_approved')
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 flex-shrink-0" title="No approver decided this — the system auto-approved it after the SLA window passed.">Auto-Approved</span>
                            @endif
                            @if($doc->disputed_at)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-rejected-50 text-rejected-700 flex-shrink-0">Disputed</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-surface-600">{{ $doc->ml_category }}</td>
                    <td class="px-4 py-3 text-surface-500">{{ $doc->originator->full_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $doc->upload_date?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $approvedAt?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3 text-surface-500 whitespace-nowrap">{{ $doc->due_date?->format('M j, Y, g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                            @if($doc->requires_printing)
                                @if(auth()->user()->isOriginator())
                                    <button type="button"
                                        onclick="event.stopPropagation(); openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }}, true)"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors whitespace-nowrap cursor-pointer">
                                        🖨 Print Required
                                    </button>
                                @else
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 whitespace-nowrap">🖨 Print Required</span>
                                @endif
                            @endif
                            <button type="button"
                                onclick="event.stopPropagation(); openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }}, false)"
                                class="inline-flex items-center border border-surface-300 text-surface-600 hover:bg-surface-50 font-medium text-xs px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap">
                                View
                            </button>
                            <a href="{{ route('archive.download', $doc) }}" onclick="event.stopPropagation()"
                                class="inline-flex items-center bg-primary-700 hover:bg-primary-800 text-white font-medium text-xs px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap">
                                Download &darr;
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-10 text-center text-surface-400 text-sm">No archived documents match your search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    {{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
         initFittedPagination() for the full mechanism. Not Laravel's own
         $documents->links() — the whole list is already in the DOM above
         (no server-side page size at all now), and this container is
         entirely built by that JS using the exact same original nav look
         as resources/views/vendor/pagination/tailwind.blade.php. --}}
    <div id="archive-results-pagination" class="px-6 py-4 border-t border-surface-200 flex-shrink-0"></div>
</div>
