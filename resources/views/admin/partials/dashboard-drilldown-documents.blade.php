{{-- KPI card drill-down: document list fragment, fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) --}}
<div class="px-6 py-2.5 border-b border-surface-200 bg-surface-50/60">
    <p class="text-xs text-surface-500">Showing {{ $documents->count() }} of {{ $total }}</p>
</div>
<div class="overflow-auto">
    {{--
        table-auto (the browser default — no table-fixed, no percentage
        widths), after several rounds of hand-guessed percentages kept
        producing columns sized for worst-case content while real rows
        (shorter titles/categories/names) left uneven dead-space gaps
        between them. table-auto sizes every column to its own real
        content instead, which is what "even" actually means — no more
        guessing. Title is the one column capped (max-w-[280px] + truncate
        below) since real titles run up to ~32 chars and one long filename
        would otherwise stretch the whole table. Status was briefly capped
        the same way too, forcing "Auto-Approved — Pending Review" onto
        two lines — verified (2026-10-01) that removing the cap lets it
        render on one line with zero horizontal overflow, so it's sized
        naturally like every other column now (Category, Originator,
        Uploaded, Approved/Rejected By, Decided At — all short/uniform
        enough, ~20-21 chars measured against real data, to size
        themselves). overflow-auto (not overflow-y-auto) on this wrapper
        is the graceful fallback if real content ever runs long enough
        across several columns at once to need it — a horizontal
        scrollbar inside the modal rather than a broken layout.
    --}}
    {{-- border-r on every column except the row's last (Status when there
         are no decision columns, Decided At when there are) — same grid-
         line convention already used on the Audit Log table
         (admin/partials/audit-results.blade.php), applied here too. --}}
    <table class="w-full text-sm">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-xs text-surface-500 font-medium">
                <th class="px-6 py-2 border-r border-surface-200">Title</th>
                <th class="px-4 py-2 border-r border-surface-200">Category</th>
                <th class="px-4 py-2 border-r border-surface-200">Originator</th>
                <th class="px-4 py-2 border-r border-surface-200">Uploaded</th>
                <th class="px-4 py-2 {{ $decisions ? 'border-r border-surface-200' : '' }}">Status</th>
                @if($decisions)
                    <th class="px-4 py-2 border-r border-surface-200 whitespace-nowrap">{{ $label === 'Rejected' ? 'Rejected By' : 'Approved By' }}</th>
                    <th class="px-4 py-2 whitespace-nowrap">Decided At</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($documents as $doc)
                {{-- align-top on every <td> (vertical-align only applies to
                     table-cell elements, not <tr> itself) — Approved
                     By/Rejected By can now list more than one person (a
                     vote, not a single decider; see AdminController::
                     resolveDecision()'s docblock), which grows that one
                     cell taller than its row-mates. Without align-top the
                     shorter single-line cells (Title, Category, Uploaded,
                     Status, Decided At) would center against that extra
                     height instead of sitting flush with the row's top. --}}
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800 truncate max-w-[280px] align-top border-r border-surface-200" title="{{ $doc->title }}">{{ $doc->title }}</td>
                    <td class="px-4 py-2.5 text-surface-600 align-top border-r border-surface-200">{{ $doc->ml_category ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-surface-600 align-top border-r border-surface-200">
                        {{ $doc->originator->full_name ?? '—' }}
                        @if($doc->originator)
                            <br><span class="text-xs text-surface-400">({{ $doc->originator->displayRole() }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-surface-500 whitespace-nowrap align-top border-r border-surface-200">{{ $doc->upload_date?->format('M j, Y g:i A') }}</td>
                    <td class="px-4 py-2.5 whitespace-nowrap align-top {{ $decisions ? 'border-r border-surface-200' : '' }}"><x-status-badge :status="$doc->display_status" /></td>
                    @if($decisions)
                        @php $decision = $decisions[$doc->document_id]; @endphp
                        <td class="px-4 py-2.5 text-surface-600 align-top border-r border-surface-200">
                            @foreach($decision['by'] as $i => $person)
                                @if($i > 0) <br> @endif
                                {{ $person['name'] }}
                                @if($person['role'])
                                    <br><span class="text-xs text-surface-400">({{ $person['role'] }})</span>
                                @endif
                            @endforeach
                        </td>
                        <td class="px-4 py-2.5 text-surface-500 whitespace-nowrap align-top">{{ $decision['at']?->format('M j, Y g:i A') ?? '—' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $decisions ? 7 : 5 }}" class="px-6 py-10 text-center text-sm text-surface-400">No documents in this category yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
