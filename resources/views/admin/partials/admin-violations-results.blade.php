{{--
    Admin Violations results — split out so this can be rendered two ways:
    inline on the full sla_violations.blade.php load, and as a fragment
    for the live-poll JS to swap in place. Scoped to whichever category
    folder this is rendered inside (see AdminController::
    adminViolationsData()).

    One row per DOCUMENT, not per violation — the stage(s) where a
    violation happened are listed underneath the title, and the single
    Open/Resolved badge reflects whether ANY of that document's stages
    still needs the one Confirm/Dispute action on the Auto-Approval
    Review page (that action reviews every pending stage on a document
    at once, so one badge per document is what actually matches it).
--}}
<div class="px-6 py-3 border-b border-surface-200 bg-surface-50/50 flex-shrink-0">
    <p class="text-sm text-surface-500">{{ $adminViolationTotal }} total in this category — an auto-approved document whose grace period for Admin review has passed without Admin confirming or disputing it.</p>
</div>

{{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
     initFittedPagination(). flex-1 + overflow-hidden here is what that JS
     measures against to decide how many rows actually fit, hiding the
     rest — same pattern as admin/partials/documents-results.blade.php. --}}
<div class="flex-1 overflow-y-hidden js-adaptive-rows-area">
<ul class="divide-y divide-surface-100">
    @forelse($adminViolations as $item)
        @php
            // Distinct from Open/Resolved — flags that the document's OWN
            // due date has now also passed while it's still sitting
            // unreviewed, not just that its (earlier) review window did.
            $isPastDue = $item->isOpen && $item->document && $item->document->due_date && $item->document->due_date->isPast();
        @endphp
        {{-- data-row-group gives initFittedPagination() a distinct fitting
             group per row (see admin/partials/audit-row.blade.php for the
             identical convention) — falls back to the loop index for the
             rare case a document was later deleted. --}}
        <li class="admin-violation-row" data-row-group="{{ $item->document->document_id ?? $loop->index }}">
            {{-- The whole row is clickable, not just the title — jumps
                 straight to this document's card on the Auto-Approval
                 Review page (see AdminController::slaQueueData()'s
                 `highlight` param), scrolled into view and briefly ringed
                 there. Falls back to a plain non-link block when the
                 document itself no longer exists, OR once resolved — a
                 resolved document has already left the Auto-Approval
                 Review page's own list (it only ever shows documents still
                 awaiting review), so `highlight` would silently match
                 nothing and just dump the admin on an unrelated page 1
                 with no explanation (confirmed 2026-10-03: this is exactly
                 what used to happen). --}}
            @if($item->document && $item->isOpen)
                <a href="{{ route('admin.sla.queue', ['highlight' => $item->document->document_id]) }}"
                    class="block px-6 py-3 hover:bg-surface-50/60 transition-colors">
            @else
                <div class="px-6 py-3">
            @endif
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium {{ $item->document && $item->isOpen ? 'text-primary-700' : 'text-surface-800' }} truncate">{{ $item->document->title ?? '—' }}</p>
                    <div class="shrink-0 flex items-center gap-1.5">
                        @if($isPastDue)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rejected-100 text-rejected-800 ring-1 ring-inset ring-rejected-500/30">
                                Past Due Date
                            </span>
                        @endif
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $item->isOpen ? 'bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20' : 'bg-approved-50 text-approved-700 ring-1 ring-inset ring-approved-500/20' }}">
                            {{ $item->isOpen ? 'Open' : 'Resolved' }}
                        </span>
                    </div>
                </div>
                {{-- Every entry here is a late_review (see adminViolationsData()) — one consistent badge, not a per-stage type distinction. --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-0.5">
                    @foreach($item->stages as $stageName)
                        <span class="text-sm text-surface-500">
                            {{ $stageName }}
                            <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">
                                Late Admin Review
                            </span>
                        </span>
                    @endforeach
                </div>
                {{-- Live-ticking "ago" only while still open — once resolved,
                     freezing it as a plain string avoids it reading as "still
                     happening" right next to a Resolved badge. --}}
                <p class="text-xs text-surface-400 mt-0.5">
                    @if($item->isOpen)
                        Flagged {{ $item->firstViolatedAt->format('M j, Y g:i A') }} (<span data-live-time="{{ $item->firstViolatedAt->timestamp }}">{{ $item->firstViolatedAt->diffForHumans() }}</span>)
                    @else
                        Flagged {{ $item->firstViolatedAt->format('M j, Y g:i A') }} ({{ $item->firstViolatedAt->diffForHumans() }})
                        @if($item->resolvedAt)
                            &middot; Resolved {{ $item->resolvedAt->format('M j, Y g:i A') }} ({{ $item->resolvedAt->diffForHumans() }})
                        @endif
                    @endif
                </p>
            @if($item->document && $item->isOpen)
                </a>
            @else
                </div>
            @endif
        </li>
    @empty
        <li class="px-6 py-8 text-center text-sm text-surface-400">No Admin violations recorded.</li>
    @endforelse
</ul>
</div>

{{-- Built entirely by initFittedPagination() — not Laravel's own
     ->links(), since the whole list is already in the DOM above and
     $adminViolations is a plain Collection now, not a real
     LengthAwarePaginator. --}}
<div id="admin-violations-results-pagination" class="px-6 py-3 border-t border-surface-100 flex-shrink-0"></div>
