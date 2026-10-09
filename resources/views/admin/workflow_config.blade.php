@extends('layouts.app')
@section('title', 'Approval Workflow')
@section('page-title', 'Approval Workflow')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-2">About this workflow</h2>
            <p class="text-xs text-surface-500 leading-relaxed">
                These are UJF's current approval stages for each document category, set up to match the company's own procedure. Every stage except Final Approval opens as soon as a document is routed; <strong>Final Approval opens only once every other stage is approved</strong>, and only a Head Approver from the owning department(s) can sign it off.
            </p>
            <p class="text-xs text-surface-500 leading-relaxed mt-2">
                Approver deadlines are calculated automatically from the working time left before each document's own due date. This page is for viewing only.
            </p>
        </div>

        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6 mt-6">
            <h2 class="text-sm font-semibold text-surface-900 mb-1">Approver Decision Restriction</h2>
            <p class="text-xs text-surface-500 mb-4">
                When on, an approver can only Approve/Reject during business hours (9 AM–5 PM, Mon–Sat) — this stops a document from being decided after-hours to dodge acting on it during paid working time. Off by default.
            </p>
            <form method="POST" action="{{ route('admin.systemSettings.businessHoursToggle') }}">
                @csrf
                {{-- Second confirmed real bug on this same control: the
                     knob used to be positioned absolute relative to the
                     whole <label> (made `relative` after the first fix
                     below), which works fine ONLY while the label's text
                     stays short enough to sit on one line. At a larger
                     text-size step (see resources/css/app.css's
                     data-text-size rules — deliberately rem-based, so the
                     WHOLE UI scales, this control included) the label text
                     wraps onto 2-3 lines in this narrow sidebar card,
                     growing the label taller; flex's items-center then
                     re-centers the track against that new (taller) height,
                     but the knob's fixed top-0.5/left-0.5 offset is still
                     measured from the LABEL's corner, not the track's new
                     position — so the knob visibly drifts away from the
                     track once the text wraps. Fixed by giving the
                     checkbox+track+knob their own small, fixed-size
                     wrapper (w-10 h-6), independent of however tall the
                     adjacent text block grows. All three stay direct
                     siblings of each other inside it, so peer-checked:
                     keeps working exactly as the FIRST fix below already
                     established (peer-checked: only ever matches a DIRECT
                     sibling of the .peer element — the underlying CSS is a
                     plain ~ sibling selector, which cannot reach into a
                     sibling's own children; that's why the knob isn't
                     nested inside the track span). --}}
                <label class="flex items-center gap-3 cursor-pointer">
                    <span class="relative inline-flex w-10 h-6 shrink-0">
                        <input type="checkbox" name="enforce_business_hours_decisions" value="1"
                            {{ $businessHoursEnforced ? 'checked' : '' }}
                            onchange="this.form.submit()"
                            class="sr-only peer">
                        <span class="absolute inset-0 bg-surface-200 peer-checked:bg-primary-700 rounded-full transition-colors"></span>
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                    <span class="text-xs font-medium text-surface-700">
                        Restrict approver decisions to business hours — currently <strong class="{{ $businessHoursEnforced ? 'text-primary-700' : 'text-surface-500' }}">{{ $businessHoursEnforced ? 'ON' : 'OFF' }}</strong>
                    </span>
                </label>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2 space-y-6" id="workflow-config-results"
        data-poll-url="{{ route('admin.workflow.config.poll') }}" data-refresh-url="{{ route('admin.workflow.config.refresh') }}">
        @include('admin.partials.workflow-config-results')
    </div>
</div>

<script>
    // Same live-poll pattern as every other admin module — see
    // dashboard.blade.php's comment for the full reasoning. An
    // assignment decided elsewhere needs to show up here without a manual
    // reload, since the pending counts below reflect live data.
    document.addEventListener('DOMContentLoaded', function () {
        const resultsEl = document.getElementById('workflow-config-results');
        if (!resultsEl) return;

        const opts = {
            refreshUrl: resultsEl.dataset.refreshUrl,
            target: resultsEl,
        };

        startLiveChannel('admin-dashboard', '.admin.activity-logged', opts);
        startLivePoll({ ...opts, pollUrl: resultsEl.dataset.pollUrl });
    });
</script>
@endsection
