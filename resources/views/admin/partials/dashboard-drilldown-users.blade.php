{{-- KPI card drill-down: full account list (active + deactivated), fetched by openKpiDrilldown() (see components/kpi-drilldown-modal.blade.php) --}}
<div class="overflow-y-auto">
    <table class="w-full text-sm">
        <thead class="bg-white sticky top-0 border-b border-surface-200">
            <tr class="text-left text-xs text-surface-500 font-medium">
                <th class="px-6 py-2">Name</th>
                <th class="px-4 py-2">Role</th>
                <th class="px-4 py-2">Category</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @forelse($users as $user)
                <tr class="hover:bg-surface-50/60">
                    <td class="px-6 py-2.5 font-medium text-surface-800">{{ $user->full_name }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $user->displayRole() }}</td>
                    <td class="px-4 py-2.5 text-surface-600">{{ $user->assigned_category ?? 'All' }}</td>
                    <td class="px-4 py-2.5">
                        @if(!$user->is_active)
                            {{-- A deactivated account is never "Not Available" the same way an
                                 active-but-offline one is — isAvailable() already folds is_active
                                 in, but collapsing both into one grey "Not Available" label hid the
                                 real distinction between "disabled account" and "enabled but
                                 offline right now". Red, matching the Active/Inactive convention
                                 already used on the main Users page (users_table.blade.php). --}}
                            <span class="text-xs font-medium text-rejected-700">Deactivated</span>
                        @else
                            {{-- isAvailable() (is_active && isOnline()) is role-agnostic — every
                                 logged-in user sends the same presence heartbeat (see User::isOnline()'s
                                 docblock) — so there's no reason this was approver-only. Display-only,
                                 same as for Approver rows; see WorkflowService's docblock on
                                 isAvailable() never gating routing for any role. --}}
                            <span class="text-xs font-medium {{ $user->isAvailable() ? 'text-approved-700' : 'text-surface-400' }}">{{ $user->isAvailable() ? 'Available' : 'Not Available' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-6 py-10 text-center text-sm text-surface-400">No user accounts yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
