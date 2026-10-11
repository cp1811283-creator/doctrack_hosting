{{--
    Document Tracking results — split out from admin/documents/index.blade.php
    so the same markup can be rendered two ways: a normal full page load,
    and a fragment returned by AdminController::documentsRefresh() for the
    live-poll JS to swap in place (see index.blade.php) without a full
    page reload.

    Each row is a flat summary, not an expandable one — the full stage
    list + movement history already lives on the document's own tracking
    page (the same one Originators use, see documents.track), so showing
    it again inline here would just be the same data twice. Clicking a
    row goes straight there instead.
--}}
{{-- flex-1 + overflow-hidden here (not on the pagination div below) — the
     parent #doc-tracking-results is capped to the device's screen height
     (see index.blade.php); this is what resources/js/app.js's
     initFittedPagination() measures against to decide how many documents
     actually fit, hiding the rest. --}}
<div class="overflow-x-auto flex-1 overflow-y-hidden js-adaptive-rows-area">
<div class="divide-y divide-surface-100">
    @forelse($documents as $doc)
        <div class="doc-tracking-row p-6 hover:bg-surface-50 cursor-pointer transition-colors" data-row-group="{{ $doc->document_id }}"
            onclick="window.location.href='{{ route('documents.track', $doc) }}'">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="text-sm font-semibold text-surface-900 truncate">{{ $doc->title }}</span>
                        <span class="text-xs text-surface-400 flex-shrink-0">#{{ $doc->document_id }}</span>
                        <x-tracking-code :code="$doc->tracking_code" class="inline-flex items-center gap-1 font-mono text-xs text-surface-400 flex-shrink-0" />
                    </div>
                    <p class="text-xs text-surface-500 mt-1">
                        Uploaded {{ $doc->upload_date?->format('M j, Y g:i:s A') }}
                        &middot; {{ $doc->originator->full_name ?? 'Unknown' }}
                        &middot; {{ $doc->ml_category ?? 'Other' }}
                    </p>
                    <div class="mt-2" onclick="event.stopPropagation()">
                        <x-document-presence :document="$doc" />
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <x-status-badge :status="$doc->display_status" />
                    <span class="text-xs font-medium text-primary-700">Track &rarr;</span>
                </div>
            </div>
        </div>
    @empty
        <div class="p-12 text-center text-sm text-surface-400">No documents match these filters.</div>
    @endforelse
</div>
</div>

{{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
     initFittedPagination() for the full mechanism. Not Laravel's own
     $documents->links() — the whole list is already in the DOM above (no
     server-side page size at all now), and this container is entirely
     built by that JS using the exact same original nav look as
     resources/views/vendor/pagination/tailwind.blade.php. --}}
<div id="doc-tracking-results-pagination" class="px-6 py-4 border-t border-surface-200 flex-shrink-0"></div>
