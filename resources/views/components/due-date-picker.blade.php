@props(['id' => 'due_date', 'name' => 'due_date', 'value' => null])

{{--
    Feature: a due-date/time picker that can't be set to Sunday or outside
    9 AM-5 PM in the first place, replacing the old native
    <input type="datetime-local"> + a reactive "that's invalid" warning
    shown only after the fact. Confirmed real gap: the server already
    rejected both (WorkflowService::isDueDateWithinWorkingHours()), but
    nothing stopped the picker itself from offering them.

    Structure: a hidden input (what the form actually submits — same
    name/format "Y-m-d\TH:i" the server already expects, so no backend
    change was needed) plus a button that opens a hand-rolled calendar +
    time-slot popup. Reuses the exact [name="business-hours"] meta tag
    layouts/app.blade.php already loads on every page for the SLA
    pause-ticker (workingDays + holidays), so this needs no new data
    source. JS: initDueDatePicker() in resources/js/app.js, called on
    every [data-due-date-picker] root (there are two instances today —
    the New Submission form and the resubmission form — so this is a
    shared component, not copy-pasted markup).

    Server-side validation (WorkflowService::isDueDateWithinWorkingHours())
    is unchanged and stays the real, authoritative check — this is a UX
    improvement, not a replacement for it.
--}}
{{--
    data-due-date-error is shown by the shared validateDueDatePicker()
    helper (resources/js/app.js), called from each form's own submit
    handler — NOT by native `required`, which has no effect on a
    type="hidden" input (browsers skip hidden fields during constraint
    validation entirely, per spec). Without this, an empty submission
    silently went all the way to the server, which rejected it and
    reloaded the WHOLE page — losing the modal and every other field the
    originator had already filled in. Confirmed real bug, not a
    hypothetical.
--}}
<div class="relative" data-due-date-picker data-buffer-minutes="{{ config('sla.min_due_date_buffer_minutes', 15) }}">
    <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" data-due-date-input>
    <button type="button"
        class="w-full flex items-center justify-between gap-2 rounded-lg border border-surface-300 text-sm px-3 py-2 text-left bg-white hover:border-primary-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
        data-due-date-trigger>
        <span data-due-date-display class="text-surface-400">Select a date &amp; time&hellip;</span>
        <svg class="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
    </button>
    <p class="hidden mt-1 text-[11px] text-rejected-700" data-due-date-error>Pick a due date &amp; time before submitting.</p>

    {{-- position: fixed, not absolute — this field commonly sits inside a
         scrollable modal body (New Submission's own form wrapper has
         overflow-y-auto), which clips an absolutely-positioned popup
         regardless of z-index. Fixed positioning escapes that clipping
         entirely; its top/left are computed from the trigger button's
         own screen position in JS on open, not CSS. z-[60] clears the
         New Submission modal's own overlay (z-50). --}}
    <div class="hidden fixed z-[60] w-72 bg-white rounded-lg shadow-lg border border-surface-200 p-3" data-due-date-popup>
        <div class="flex items-center justify-between mb-2">
            <button type="button" class="p-1 rounded hover:bg-surface-100 text-surface-500" data-due-date-prev aria-label="Previous month">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <span class="text-sm font-semibold text-surface-800" data-due-date-month-label></span>
            <button type="button" class="p-1 rounded hover:bg-surface-100 text-surface-500" data-due-date-next aria-label="Show the following month">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
        <div class="grid grid-cols-7 gap-0.5 text-center text-[10px] font-semibold text-surface-400 uppercase mb-1">
            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
        </div>
        <div class="grid grid-cols-7 gap-0.5" data-due-date-days></div>

        {{-- A real, typeable time field (not a fixed menu of 30-minute
             buttons — confirmed real complaint: that design prevented
             entering anything PRECISE even when it would've been valid,
             not just anything invalid). Still fully clamped to 9 AM-5 PM
             — but instead of letting you type an out-of-range time and
             then rejecting it with an error message after you click
             "Set", the button itself disables the instant the typed
             value falls outside business hours (same disable-don't-error
             treatment the calendar's day grid already uses for
             Sundays/holidays), so an invalid time is never settable in
             the first place. See updateTimeApplyState() in
             resources/js/app.js. --}}
        <div class="hidden mt-3 pt-3 border-t border-surface-100" data-due-date-times-wrap>
            <p class="text-xs font-medium text-surface-600 mb-1.5">Time (9 AM&ndash;5 PM)</p>
            <div class="flex items-center gap-2">
                <input type="time" min="09:00" max="16:59" step="60"
                    class="flex-1 rounded-lg border-surface-300 text-sm px-2 py-1.5 focus:border-primary-500 focus:ring-primary-500"
                    data-due-date-time-input>
                <button type="button"
                    class="shrink-0 px-3 py-1.5 rounded-lg bg-primary-700 hover:bg-primary-800 text-white text-xs font-semibold transition-colors disabled:bg-surface-300 disabled:hover:bg-surface-300"
                    data-due-date-time-apply title="Enter a time first.">Set</button>
            </div>
        </div>

        <p class="text-[11px] text-surface-400 mt-3 pt-2 border-t border-surface-100">
            Sundays and holidays aren't selectable — approver deadlines only run during business hours.
        </p>
    </div>
</div>
