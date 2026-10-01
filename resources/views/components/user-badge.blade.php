{{--
    Identity block: Department/Category/Stage line (approvers only), name,
    role pill. Originally the top-right header's own identity block
    (auth()->user() only); parameterized with an optional :user (defaults
    to auth()->user(), so the header usage below is unchanged) and :center
    (default true) so the exact same card can be reused left-aligned in a
    list row — see chat/partials/user-list.blade.php, the Admin chat
    panel's "every user" list (Feature: chat).

    Department / category / stage only exist for approvers (see the
    add_department_and_level_to_users_table migration's docblock — both
    columns are approver-specific and null for admin/originator), so that
    line is only ever shown for the approver role; other roles just get
    their name and a role pill, same information the old single pill gave,
    just paired with the name now.
--}}
@props(['user' => null, 'center' => true])
@php
    $user = $user ?? auth()->user();
    $isApprover = $user->role === 'approver';

    if ($isApprover) {
        // Mirrors DocumentController::stagesLabelFor()'s "empty ->
        // eligible for every stage in my category" reasoning — an empty
        // workflowStages() relation means unrestricted, not "handles no
        // stages," so that has to read as "All Stages," not blank.
        $stageNames = $user->workflowStages()->orderBy('sequence_order')->pluck('stage_name');
        $stageLabel = $stageNames->isNotEmpty() ? $stageNames->implode(' / ') : 'All Stages';
    }
@endphp
<div class="{{ $center ? 'text-center' : 'text-left' }} leading-tight">
    @if($isApprover)
        <p class="text-xs text-surface-500 truncate max-w-[140px] sm:max-w-[260px]">
            {{ $user->department ?? '—' }} | {{ $user->assigned_category ?? '—' }} | {{ $stageLabel }}
        </p>
    @endif
    <p class="text-sm font-semibold text-surface-900 truncate max-w-[140px] sm:max-w-[260px]">{{ $user->full_name }}</p>
    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-500/15">
        {{ $user->displayRole() }}
    </span>
</div>
