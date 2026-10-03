{{--
    Body fragment for the "Manage Stages" popup — fetched into
    components/kpi-drilldown-modal.blade.php by the "Manage Stages" button
    in admin/partials/users_table.blade.php (see
    AdminController::editApproverStages()). Same form, fields, and submit
    target as always — only the container changed, from a dedicated full
    page to a popup opened on demand, consistent with the Document Tracker
    and Import Legacy Document popups.

    The category/department stage-filtering script below is initialized by
    openKpiDrilldown() itself (see kpi-drilldown-modal.blade.php's
    initManageStagesForm()), not a <script> tag here — content set via
    innerHTML never executes its own <script> tags, so this markup relies
    on ids (#edit-category, #edit-department, .stage-group, .stage-option)
    that function already knows to look for after any drilldown loads.
--}}
<div class="p-6">
    <p class="text-sm text-surface-600 mb-1">
        <span class="font-medium text-surface-800">{{ $user->full_name }}</span>
        &middot; <span class="text-xs text-surface-400">{{ $user->username }}</span>
    </p>
    <p class="text-xs text-surface-500 mb-4">
        Changing category resets stage picks to "every stage in the new category" — old stage picks
        wouldn't make sense there. Leave everything unchecked to keep {{ $user->full_name }} eligible
        for <strong>every</strong> stage in whichever category is selected (default).
    </p>

    @if($pendingInOldCategory > 0)
        <div class="mb-4 rounded-lg bg-processing-50 border border-processing-500/30 text-processing-700 px-4 py-3 text-xs">
            {{ $user->full_name }} currently has <strong>{{ $pendingInOldCategory }}</strong> pending
            assignment(s) in their queue. Reassigning category/stages does <strong>not</strong> affect
            these — they stay in the queue exactly as-is and can still be decided normally.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.stages.update', $user) }}" class="space-y-4">
        @csrf

        <div>
            <label class="block text-xs font-medium text-surface-700 mb-1">Level</label>
            <select name="level" id="edit-level" required
                class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                @foreach(\App\Models\User::knownLevels() as $l)
                    <option value="{{ $l }}" @selected($user->level === $l)>{{ ucfirst($l) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-surface-700 mb-1">
                Department <span class="text-surface-400 font-normal">(determines which stages below can be picked)</span>
            </label>
            <select name="department" id="edit-department" required
                class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                @foreach(\App\Models\User::knownDepartments() as $d)
                    <option value="{{ $d }}" @selected($user->department === $d)>{{ $d }}</option>
                @endforeach
            </select>
        </div>

        <div id="edit-category-field">
            <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
            <select name="assigned_category" id="edit-category" required
                class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                @foreach(\App\Services\ValidationService::knownCategories() as $c)
                    <option value="{{ $c }}" @selected($user->assigned_category === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>

        <p id="edit-head-note" class="hidden text-xs text-surface-500 -mt-2">
            Heads sit on Final Approval across <strong>every</strong> category for their department — no category or stage selection needed.
        </p>

        <div id="edit-stages-field">
            <label class="block text-xs font-medium text-surface-700 mb-1">
                Specific Stages <span class="text-surface-400 font-normal">(optional — leave all unchecked for every stage this department owns)</span>
            </label>
            @foreach($stagesByCategory as $category => $categoryStages)
                <div class="stage-group space-y-1 {{ $category !== $user->assigned_category ? 'hidden' : '' }}" data-category="{{ $category }}">
                    @forelse($categoryStages as $stage)
                        {{-- This picker only ever applies to a Staff-level
                             account (Head hides the whole field — see
                             initManageStagesForm() in kpi-drilldown-modal.
                             blade.php) — Final Approval is head-only
                             (WorkflowService::eligibleApproversForStage()),
                             so a Staff account can never actually hold it
                             regardless of what's checked here. Omitted, not
                             just disabled, so there's nothing misleading to
                             check in the first place. --}}
                        @continue($stage->stage_name === 'Final Approval')
                        @php($owners = $stage->departmentNames())
                        <label class="stage-option flex items-start gap-3 p-3 rounded-lg border border-surface-200 hover:bg-surface-50 cursor-pointer"
                            data-departments="{{ implode(',', $owners) }}">
                            <input type="checkbox" name="stage_ids[]" value="{{ $stage->stage_id }}"
                                @checked($category === $user->assigned_category && in_array($stage->stage_id, $assignedStageIds))
                                class="mt-0.5 rounded border-surface-300 text-primary-700 focus:ring-primary-500">
                            <span>
                                <span class="block text-sm font-medium text-surface-800">
                                    {{ $stage->sequence_order }}. {{ $stage->stage_name }}
                                    @if($owners)
                                        <span class="text-surface-400 font-normal">({{ implode(' + ', $owners) }})</span>
                                    @endif
                                </span>
                                @if($stage->description)
                                    <span class="block text-xs text-surface-400">{{ $stage->description }}</span>
                                @endif
                            </span>
                        </label>
                    @empty
                        <p class="text-xs text-surface-400">No stages configured for {{ $category }} yet.</p>
                    @endforelse
                </div>
            @endforeach
        </div>

        <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
            Save
        </button>
    </form>
</div>
