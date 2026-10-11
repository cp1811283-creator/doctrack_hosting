{{--
    The submissions table + pagination — split out from dashboard.blade.php
    so the same markup can be rendered two ways: a normal full page load,
    and a fragment returned by DocumentController::refresh() for the
    live-poll JS to swap in place (see dashboard.blade.php) without a full
    page reload. The "N total" count in the card header is a sibling of
    this fragment, not part of it — the JS reads the value below (baked
    into the fragment itself, not the triggering event's payload, since a
    WebSocket push and a poll response don't share the same JSON shape).
--}}
<span data-total-count="{{ $documents->count() }}" class="hidden"></span>
{{-- flex-1 + overflow-hidden here (not on the pagination div below) — the
     parent #submissions-list is capped to the device's screen height (see
     dashboard.blade.php); this is what resources/js/app.js's
     initFittedPagination() measures against to decide how many documents
     actually fit, hiding the rest — see that function's own docblock for
     the full mechanism. --}}
<div class="overflow-x-auto flex-1 overflow-y-hidden js-adaptive-rows-area">
<table class="w-full min-w-[640px] text-sm">
    <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
        <tr>
            <th class="text-left px-6 py-3 font-medium">Document</th>
            <th class="text-left px-6 py-3 font-medium">Batch</th>
            <th class="text-left px-6 py-3 font-medium">Category</th>
            <th class="text-left px-6 py-3 font-medium">Status</th>
            <th class="text-left px-6 py-3 font-medium">Uploaded</th>
            <th class="px-6 py-3"></th>
        </tr>
    </thead>
    <tbody id="submission-rows" class="divide-y divide-surface-100">
        @forelse($documents as $doc)
            {{-- data-row-group ties this row to its own "Validation issues"
                 row right below (same document_id on both) — initFittedPagination()
                 always shows or hides the two together, one logical item. --}}
            <tr class="submission-row hover:bg-surface-50 transition-colors" data-document-title="{{ strtolower($doc->title.' '.$doc->tracking_code) }}" data-row-group="{{ $doc->document_id }}">
                <td class="px-6 py-4 font-medium text-surface-800 max-w-xs">
                    <div class="truncate">
                        {{ $doc->title }}
                        @if($doc->version_number > 1)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 text-indigo-700 align-middle">v{{ $doc->version_number }}</span>
                        @endif
                    </div>
                    <x-tracking-code :code="$doc->tracking_code" class="inline-flex items-center gap-1 font-mono text-[11px] text-surface-400 font-normal mt-0.5" />
                </td>
                <td class="px-6 py-4 text-surface-500">
                    @if($doc->batch)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/20 whitespace-nowrap">
                            Batch #{{ $doc->batch_id }}
                        </span>
                    @else
                        <span class="text-surface-300">—</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-surface-600">{{ $doc->display_category ?? '—' }}</td>
                <td class="px-6 py-4"><x-status-badge :status="$doc->display_status" /></td>
                <td class="px-6 py-4 text-surface-500 whitespace-nowrap">{{ $doc->upload_date->format('M j, Y, g:i A') }}</td>
                <td class="px-6 py-4 text-right">
                    {{-- No whitespace-nowrap here (unlike the Batch badge
                         above) — "Select Approver(s) →" is long enough
                         that forcing it onto one line pushed this column
                         wider than the table's own scroll container,
                         scrolling the buttons out of view entirely on a
                         normal-width screen. Letting it wrap back onto
                         two lines when needed keeps the column narrow. --}}
                    @if($doc->pending_custom_routing_at)
                        <button type="button"
                            onclick="openKpiDrilldown('select-approvers', 'Select Approver(s) — {{ addslashes($doc->title) }}', '{{ route('originator.documents.selectApprovers', $doc) }}')"
                            class="inline-flex items-center px-2 py-1 rounded-full bg-amber-50 hover:bg-amber-100 text-amber-700 hover:text-amber-900 font-semibold text-xs transition-colors">Select Approver(s) &rarr;</button>
                    @else
                        <a href="{{ route('originator.documents.show', $doc) }}" class="inline-flex items-center px-2 py-1 rounded-full bg-primary-50 hover:bg-primary-100 text-primary-700 hover:text-primary-900 font-medium text-xs transition-colors">Track &rarr;</a>
                    @endif
                </td>
            </tr>
            @if(!$doc->is_validated && $doc->global_status === 'processing')
            <tr class="submission-row bg-rejected-50/50" data-document-title="{{ strtolower($doc->title.' '.$doc->tracking_code) }}" data-row-group="{{ $doc->document_id }}">
                <td colspan="6" class="px-6 pb-3 text-xs text-rejected-700">
                    Validation issues: {{ implode(' · ', $doc->validation_errors ?? []) }}
                </td>
            </tr>
            @endif
        @empty
            <tr>
                <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">
                    @if(request('document') || request('status') || request('category'))
                        No documents match these filters.
                    @else
                        No documents submitted yet.
                    @endif
                </td>
            </tr>
        @endforelse
        <tr id="submission-no-matches" class="hidden">
            <td colspan="6" class="px-6 py-10 text-center text-surface-400 text-sm">No documents on this page match "<span id="submission-no-matches-term"></span>". Press Enter to search every page.</td>
        </tr>
    </tbody>
</table>
</div>

{{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
     initFittedPagination() for the full mechanism. Not Laravel's own
     $documents->links() — the whole list is already in the DOM above (no
     server-side page size at all now), and this container is entirely
     built by that JS using the exact same original nav look as
     resources/views/vendor/pagination/tailwind.blade.php. --}}
<div id="submissions-list-pagination" class="px-6 py-4 border-t border-surface-200 flex-shrink-0"></div>
