{{--
    The account list card — split out so the same markup renders both a
    normal full page load and the fragment AdminController::usersRefresh()
    returns for the live-channel/poll JS on users.blade.php to swap in
    place (see resources/js/app.js's applyLiveRefresh pattern). Lets the
    "Unverified" badge/Resend-verification action disappear live the
    moment someone actually clicks their link, instead of only on a
    manual reload — see AuthController::verifyEmail() firing UserVerified.

    Capped to the device's own viewport height (Feature: pagination, not
    scrolling or overflow, absorbs a long account list). Height is set by
    JS (sizeCappedCard() in resources/js/app.js, called from admin/users.
    blade.php) rather than a static class — this whole card gets swapped
    wholesale on every live refresh (the target is #users-table, the outer
    div in admin/users.blade.php), so admin/users.blade.php's onSwap
    re-runs it every time rather than it living as a one-time class that
    would just be lost with the rest of this element on the next swap.
--}}
<div id="users-list"
    class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden flex flex-col">
    <div class="px-6 py-3 border-b border-surface-200 flex items-center justify-between flex-shrink-0">
        <span class="text-xs text-surface-400">
            @if(!$showInactive && $inactiveCount > 0)
                {{ $inactiveCount }} inactive account{{ $inactiveCount === 1 ? '' : 's' }} hidden
            @endif
        </span>
        @if($showInactive)
            <a href="{{ url()->current() }}" class="text-xs font-medium text-primary-700 hover:underline">Hide inactive accounts</a>
        @elseif($inactiveCount > 0)
            <a href="{{ url()->current() }}?show_inactive=1" class="text-xs font-medium text-primary-700 hover:underline">Show inactive accounts</a>
        @endif
    </div>
    {{-- flex-1 + overflow-hidden here (not on the pagination nav below) —
         js-adaptive-rows-area is the stable hook initFittedPagination()
         (see resources/js/app.js) measures against to work out every
         page's real boundary — see originator/partials/submissions.blade.php's
         identical reasoning. --}}
    <div class="overflow-x-auto flex-1 overflow-y-hidden js-adaptive-rows-area">
    <table class="w-full table-fixed text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-3 py-3 font-medium w-[8%]">ID</th>
                <th class="text-left px-3 py-3 font-medium w-[20%]">Name</th>
                <th class="text-left px-3 py-3 font-medium w-[10%]">Role</th>
                <th class="text-left px-3 py-3 font-medium w-[11%]">Department</th>
                <th class="text-left px-3 py-3 font-medium w-[17%]">Category / Stages</th>
                <th class="text-left px-3 py-3 font-medium w-[8%]">Status</th>
                <th class="px-3 py-3 w-[26%]">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @foreach($users as $u)
                {{-- data-row-group lets initFittedPagination() treat this
                     row as one logical item when working out page
                     boundaries — a single <tr> per user today, but this
                     keeps the same convention as submissions.blade.php's
                     document + validation-issues row pair. --}}
                <tr class="user-row hover:bg-surface-50" data-row-group="{{ $u->user_id }}">
                    <td class="px-3 py-3 text-surface-500 truncate">#{{ $u->user_id }}</td>
                    <td class="px-3 py-3">
                        <p class="font-medium text-surface-800 truncate">{{ $u->full_name }}</p>
                        <p class="text-xs text-surface-400 truncate">{{ $u->email }}</p>
                        @unless($u->hasVerifiedEmail())
                            <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-processing-100 text-processing-700">
                                Unverified
                            </span>
                        @endunless
                    </td>
                    <td class="px-3 py-3 capitalize text-surface-600">
                        {{ $u->role }}
                        @if($u->role === 'approver')
                            <br>
                            @if($u->level)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $u->level === 'head' ? 'bg-approved-50 text-approved-700 ring-1 ring-inset ring-approved-500/20' : 'bg-surface-100 text-surface-600 ring-1 ring-inset ring-surface-500/20' }}">
                                    {{ ucfirst($u->level) }}
                                </span>
                                <br>
                            @endif
                        @elseif($u->role === 'originator')
                            <br>
                            {{-- Every Originator is Staff-tier — there's no Head-Originator
                                 concept, so this pill is unconditional, not read from $u->level
                                 the way Approver's is (see User::displayRole()'s docblock).
                                 Same grey "Staff" pill styling as Approver's, for consistency. --}}
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-surface-100 text-surface-600 ring-1 ring-inset ring-surface-500/20">
                                Staff
                            </span>
                            <br>
                        @else
                            <br>
                        @endif
                        {{-- isAvailable() (is_active && isOnline()) is role-agnostic — every
                             logged-in user sends the same presence heartbeat. This page already
                             has its own separate Active/Inactive Status column, so unlike the
                             All Users drilldown (which has no such column) there's no need to
                             fold "Deactivated" in here too — just real online status for every
                             role, matching what Approver rows already showed. --}}
                        <span class="text-xs font-medium {{ $u->isAvailable() ? 'text-approved-700' : 'text-surface-400' }}">
                            {{ $u->isAvailable() ? 'Available' : 'Not Available' }}
                        </span>
                    </td>
                    <td class="px-3 py-3">
                        @if($u->role === 'approver' && $u->department)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">
                                {{ $u->department }}
                            </span>
                        @else
                            <span class="text-xs text-surface-400">&mdash;</span>
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        @if($u->role === 'admin')
                            <span class="text-xs text-surface-400">All categories</span>
                        @elseif($u->role === 'approver')
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/20">
                                {{ $u->assigned_category ?? 'Unassigned' }}
                            </span>
                            <p class="text-xs text-surface-400 mt-1 truncate" title="{{ $u->workflowStages->isEmpty() ? 'All stages' : $u->workflowStages->pluck('stage_name')->implode(', ') }}">
                                @if($u->workflowStages->isEmpty())
                                    All stages
                                @else
                                    {{ $u->workflowStages->pluck('stage_name')->implode(', ') }}
                                @endif
                            </p>
                        @else
                            <span class="text-xs text-surface-400">Any category</span>
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        <span class="text-xs font-medium {{ $u->is_active ? 'text-approved-700' : 'text-rejected-700' }}">
                            {{ $u->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    {{-- Stacked, background-styled pill buttons instead of inline
                         underlined text links — reads clearly as clickable actions,
                         and stacking vertically (rather than the previous 3-across
                         whitespace-nowrap row) is what actually let this column
                         shrink enough for the whole table to fit without a
                         horizontal scrollbar, on top of the table-fixed/percentage
                         column widths above. --}}
                    <td class="px-3 py-3">
                        <div class="flex flex-col items-center gap-1">
                            @if($u->role === 'approver')
                                {{-- Opens the shared popup modal instead of
                                     navigating to its own page — see
                                     AdminController::editApproverStages()'s
                                     matching comment. --}}
                                <button type="button"
                                    onclick="openKpiDrilldown('manage-stages', '{{ addslashes($u->full_name) }} — Category &amp; Stages', '{{ route('admin.users.stages.edit', $u) }}')"
                                    class="inline-flex items-center bg-primary-50 hover:bg-primary-100 text-primary-700 font-medium text-[11px] px-2 py-1 rounded-lg transition-colors">
                                    Manage Stages
                                </button>
                            @endif
                            @unless($u->hasVerifiedEmail())
                                <form method="POST" action="{{ route('admin.users.resend-verification', $u) }}">
                                    @csrf
                                    <button class="inline-flex items-center bg-processing-50 hover:bg-processing-100 text-processing-700 font-medium text-[11px] px-2 py-1 rounded-lg transition-colors">
                                        Resend verification
                                    </button>
                                </form>
                            @endunless
                            @if($u->is_active)
                                {{-- A centered modal (same pattern as x-document-viewer-modal),
                                     not a popover anchored to this button — an absolutely-
                                     positioned popover still gets clipped by the table's
                                     overflow-x-auto scroll container (position:absolute escapes
                                     normal document FLOW, not an ancestor's overflow clipping),
                                     so it was overflowing/getting cut off regardless. fixed
                                     inset-0 escapes that entirely by anchoring to the viewport,
                                     not this row. Deactivating an approver with pending work
                                     triggers an automatic handoff (see AdminController::
                                     toggleUser()); this optional note just gives the new
                                     approver context for why. --}}
                                <details data-popover class="inline-block text-left">
                                    <summary class="inline-flex items-center bg-rejected-50 hover:bg-rejected-100 text-rejected-700 font-medium text-[11px] px-2 py-1 rounded-lg cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                        Deactivate
                                    </summary>
                                    <div class="fixed inset-0 z-50 bg-surface-900/50 flex items-center justify-center p-4"
                                        onclick="if(event.target === this) this.closest('details').open = false">
                                        <form method="POST" action="{{ route('admin.users.toggle', $u) }}" class="flex flex-col w-full max-w-sm bg-white shadow-elevated border border-surface-200 rounded-xl p-5">
                                            @csrf
                                            <h3 class="text-sm font-semibold text-surface-900 mb-3">Deactivate {{ $u->full_name }}?</h3>
                                            <label class="block text-[11px] font-medium text-surface-600 mb-1">Reason (optional)</label>
                                            <textarea name="reason" rows="3" maxlength="500" placeholder="e.g. Resigned, role change…"
                                                class="block w-full rounded-lg border-surface-300 text-xs px-2 py-1.5 focus:border-primary-500 focus:ring-primary-500 mb-3"></textarea>
                                            <button type="submit" class="block w-full bg-rejected-600 hover:bg-rejected-700 text-white text-xs font-medium py-2 rounded-lg transition-colors">
                                                Confirm Deactivation
                                            </button>
                                        </form>
                                    </div>
                                </details>
                            @else
                                <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                    @csrf
                                    <button class="inline-flex items-center bg-approved-50 hover:bg-approved-100 text-approved-700 font-medium text-[11px] px-2 py-1 rounded-lg transition-colors">
                                        Activate
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <p class="px-6 py-2 text-xs text-surface-400 border-t border-surface-200 bg-surface-50 flex-shrink-0">
        An approver's category and specific stage assignments can be changed anytime via "Manage Category & Stages" — this only affects future document routing, never assignments they already hold. Originators are never category-restricted.
    </p>
    {{-- Feature: client-side "fitted" pagination — see resources/js/app.js's
         initFittedPagination() for the full mechanism. This container is
         entirely built by that JS (same original nav look as
         resources/views/vendor/pagination/tailwind.blade.php, hand-built
         here since a real LengthAwarePaginator no longer drives it — the
         whole list is already in the DOM above and page boundaries are
         computed from real measured heights). --}}
    <div id="users-list-pagination" class="px-6 py-4 border-t border-surface-200 flex-shrink-0"></div>
</div>
