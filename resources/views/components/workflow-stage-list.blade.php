@props(['document', 'highlightAssignmentId' => null, 'showDueDate' => true])

{{--
    Full workflow-stage pipeline for this document's category (Feature:
    show every stage, not just whichever one currently "pops up"). Every
    configured stage for the category is listed and its state derived from
    however many assignment rows (seats — one per eligible approver, see
    WorkflowService::assignStage()) exist for it:
      - no assignment rows yet                  -> "upcoming" (not yet reached)
      - any seat rejected                       -> "rejected"
      - zero seats pending, none rejected        -> "approved"/"auto_approved"
      - otherwise (1+ seats still pending)       -> "pending", with a
        "N of M approved" progress line once a stage has more than one seat.

    Highlighting (Feature: approver can see which stages are theirs at a
    glance): when the viewer is an approver, their own pending seat(s) for
    this document are highlighted in primary blue instead of the neutral
    orange used for other approvers' pending stages. If they hold pending
    seats on more than one stage, only the earliest (lowest sequence_order)
    is marked "Your turn" — that's the one the action button elsewhere on
    the page acts on. Their other stage(s) are marked "Up next" — visible,
    but not yet actionable until the current one resolves.

    Explicit override (Feature: Admin SLA Override Queue): the viewer isn't
    the assigned approver, so the auth-based highlighting above never
    fires. Pass $highlightAssignmentId to force the ONE SPECIFIC SEAT that
    assignment belongs to into the same highlighted style (assignment-
    scoped, not stage-scoped, since a stage can now have some escalated
    seats and some non-escalated seats at once) — labeled "Escalated"
    rather than "Your turn" since it isn't the viewer's own assignment.

    Originator-directed routing (Feature: bypass the standard pipeline —
    see WorkflowService::routeToCustomApprovers()): a custom-routed
    document never touches the category's configured pipeline at all, so
    showing it here would list stages that will never actually happen as
    if they're still coming ("Not yet reached" forever). Below, this
    shows ONLY the document's own one-off stage(s) in that case instead
    of the category-wide list — see desired_routing's branch.

    $showDueDate (default true): pass false from a caller whose own card
    header already shows a "Due {date}" line (approver review popup,
    admin SLA queue) — this component's own "Due:" line would otherwise
    duplicate it. The originator tracking page has no other due-date
    display, so it keeps the default.
--}}
@php
    // A document with no ml_category yet (still processing, or extraction/
    // classification never completed) has no workflow pipeline to show —
    // WorkflowStage::forCategory() requires a non-null category string.
    // Checked against the two explicit opt-out values, not "!== 'auto'"
    // — see ApprovalForecastService::estimateFor()'s identical comment
    // for why: desired_routing defaults to 'auto' at the DB level, but an
    // unrefreshed in-memory model reads it as null until reloaded.
    //
    // is_archived=false (confirmed real bug, 2026-10-03): neither
    // configured() nor forCategory() filter archived status, so this was
    // the one place in the app that kept showing an archived stage forever
    // regardless of category or document — every other stage-aware query
    // (WorkflowService::routeToWorkflow(), openFinalApprovalIfReady(), …)
    // already excludes it; this component was the one that got missed.
    $allStages = match(true) {
        !$document->ml_category => collect(),
        in_array($document->desired_routing, ['custom', 'unrelated'], true) => \App\Models\WorkflowStage::where('document_id', $document->document_id)->orderBy('sequence_order')->get(),
        default => \App\Models\WorkflowStage::configured()->forCategory($document->ml_category)->where('is_archived', false)->get(),
    };
    $assignmentsByStage = $document->assignments->groupBy('stage_id');
    $currentUserId = auth()->id();

    $myActiveStageId = $document->assignments
        ->where('individual_status', 'pending')
        ->where('user_id', $currentUserId)
        ->sortBy(fn ($a) => $a->stage->sequence_order)
        ->pluck('stage_id')
        ->first();

    // "Opened <date>" / "Reviewed <pass 1>, <pass 2>, … — <total>" block on
    // a resolved seat — shared formatting for both the single-seat and
    // multi-seat branches below. Each review pass is listed with its own
    // span rather than collapsed into one outer from/to range, since a
    // second pass often starts well after the first one ended and a
    // single range would silently count that gap as if it were reviewing
    // time.
    $fmtDuration = function (int $seconds) {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    };
    $reviewSummaryFor = function (int $approverId) use ($document, $fmtDuration) {
        $sessions = \App\Models\DocumentReviewSession::closedSessionsFor($document->document_id, $approverId);
        if ($sessions->isEmpty()) {
            return null;
        }

        $totalSeconds = (int) $sessions->sum('duration_seconds');

        $segments = $sessions->map(function ($session) use ($fmtDuration) {
            $from = $session->opened_at->format('g:i A');
            $to = $session->closed_at->isSameDay($session->opened_at)
                ? $session->closed_at->format('g:i A')
                : $session->closed_at->format('M j, g:i A');
            return "{$from}–{$to} ({$fmtDuration($session->duration_seconds ?? 0)})";
        })->implode(', ');

        return [
            'opened' => $sessions->first()->opened_at,
            'segments' => $segments,
            'total' => $fmtDuration($totalSeconds),
        ];
    };
@endphp

@if(!$document->ml_category)
    <div class="rounded-lg border border-surface-200 bg-surface-50 p-3 text-sm text-surface-500">
        This document hasn't been classified yet, so it has no workflow stages to show.
        @if($document->validation_errors)
            See the validation issue below for why.
        @endif
    </div>
@elseif($allStages->isEmpty() && $document->pending_custom_routing_at)
    {{-- Originator-directed routing, awaiting the pick — no one-off
         stage exists yet at all (routeToCustomApprovers() creates it),
         so there's genuinely nothing to list here yet. --}}
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
        Awaiting the originator's approver selection — this document's approval process hasn't been set up yet.
    </div>
@else
@php
    // Statistical estimate only — see ApprovalForecastService's docblock
    // for why this isn't a trained model yet. Omitted once the document
    // is fully resolved (nothing left to estimate) or when there's no
    // historical data yet for this category — estimateFor() itself now
    // falls back to each stage's own SLA window in that case, so this
    // still comes back non-null far more often than it used to; it's
    // null here only once every stage is resolved.
    $isResolved = in_array($document->global_status, ['approved', 'auto_approved', 'rejected']);
    $forecast = $isResolved ? null : app(\App\Services\ApprovalForecastService::class)->estimateFor($document);
@endphp
{{-- Due is shown independently of whether an estimate could be computed
     — it's a known fact about the document either way, not a derived
     guess, so it shouldn't disappear just because $forecast is null. --}}
@if(!$isResolved && ($forecast || ($showDueDate && $document->due_date)))
    @php
        if ($forecast) {
            // Business-hours-aware, not plain wall-clock addition — the same
            // helper every sla_expires_at deadline already uses, so this can
            // never claim an approval will land at 8 PM or on a Sunday.
            $estApprovalBy = app(\App\Services\BusinessHoursService::class)
                ->addBusinessMinutes(now(), (int) ceil($forecast->totalSeconds / 60));

            // Never claim a later estimate than the document's own hard
            // deadline — a forecast "after your due date" isn't a useful
            // estimate; the workflow's own SLA machinery (escalation,
            // auto-approval) forces a resolution well before that regardless.
            // This also guards against ApprovalForecastService's historical
            // average getting skewed unrealistically high by a sparse/slow
            // decision history (common early on, before real usage settles
            // into a consistent pace) and ballooning the estimate out to
            // absurd, due-date-defying lengths.
            if ($document->due_date && $estApprovalBy->greaterThan($document->due_date)) {
                $estApprovalBy = \Carbon\Carbon::parse($document->due_date);
            }
        }
    @endphp
    <p class="text-sm text-surface-500 mb-2 flex items-center justify-between gap-3">
        @if($forecast)
            <span><span class="font-medium text-surface-600">Est. Approval by:</span> {{ $estApprovalBy->format('M j, Y, g:i A') }}</span>
        @endif
        @if($showDueDate && $document->due_date)
            <span><span class="font-medium text-surface-600">Due:</span> {{ $document->due_date->format('M j, Y, g:i A') }}</span>
        @endif
    </p>
@endif
<div class="space-y-2">
    @foreach($allStages as $stage)
        @php
            // Withdrawn seats (deactivated approver, already covered by a
            // sibling — see WorkflowService::withdrawAssignment()) are
            // excluded here entirely rather than shown resolved: there's
            // nothing to report for them, the remaining seat(s) already
            // fully represent this stage's outcome. They still exist in
            // the audit trail/movement timeline for history.
            $stageAssignments = $assignmentsByStage->get($stage->stage_id, collect())
                ->reject(fn ($a) => $a->individual_status === 'withdrawn');
            $totalSeats = $stageAssignments->count();
            $approvedSeats = $stageAssignments->whereIn('individual_status', ['approved', 'auto_approved']);
            $rejectedSeats = $stageAssignments->where('individual_status', 'rejected');
            $pendingSeats = $stageAssignments->where('individual_status', 'pending');

            $state = match(true) {
                $stageAssignments->isEmpty() => 'upcoming',
                $rejectedSeats->isNotEmpty() => 'rejected',
                $pendingSeats->isEmpty() => ($approvedSeats->contains(fn ($a) => $a->auto_approved) ? 'auto_approved' : 'approved'),
                default => 'pending',
            };

            $myAssignment = $pendingSeats->first(fn ($a) => $a->user_id === $currentUserId);
            $isMine = (bool) $myAssignment;
            $isForcedHighlight = $highlightAssignmentId !== null && $stageAssignments->contains('assignment_id', $highlightAssignmentId);
            $isMyActive = ($isMine && $stage->stage_id === $myActiveStageId) || $isForcedHighlight;

            // For "single seat" stages, keep referring to that one row the
            // same way the component always has — most stages only have
            // one eligible approver, and this keeps that common case
            // visually identical to before.
            $soleAssignment = $totalSeats === 1 ? $stageAssignments->first() : null;
        @endphp
        <div class="flex items-start gap-3 rounded-xl border p-3 transition-colors
            @if($isMyActive) border-primary-500 bg-primary-50 ring-1 ring-inset ring-primary-500/40 shadow-sm
            @elseif($isMine) border-primary-200 bg-primary-50/40
            @elseif($state === 'pending') border-processing-500/30 bg-processing-50/40
            @else border-surface-200 @endif">
            {{-- auto_approved gets its own amber gradient, not the same
                 green as a real human approval — a document that was
                 only auto-approved because nobody acted in time hasn't
                 actually been signed off by a person yet, and shouldn't
                 look identical to one that has. --}}
            <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-sm mt-0.5
                @if($state === 'approved') bg-gradient-to-br from-approved-500 to-approved-600 text-white
                @elseif($state === 'auto_approved') bg-gradient-to-br from-amber-500 to-amber-600 text-white
                @elseif($state === 'rejected') bg-gradient-to-br from-rejected-500 to-rejected-600 text-white
                @elseif($isMyActive) bg-gradient-to-br from-primary-500 to-primary-700 text-white
                @elseif($state === 'pending') bg-gradient-to-br from-processing-500 to-processing-600 text-white
                @else bg-surface-200 text-surface-400 shadow-none @endif">
                @if(in_array($state, ['approved', 'auto_approved']))
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                @elseif($state === 'rejected')
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                @else
                    {{ $stage->sequence_order }}
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-800 flex items-center gap-1.5 flex-wrap">
                    {{ $stage->stage_name }}
                    @if($isForcedHighlight)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-rejected-100 text-rejected-700">Escalated</span>
                    @elseif($isMyActive)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold text-gray-600">Your turn</span>
                    @elseif($isMine)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-primary-100 text-primary-700">Up next</span>
                    @endif
                    @if($totalSeats > 1 && $state === 'pending')
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-surface-100 text-surface-600">{{ $approvedSeats->count() }} of {{ $totalSeats }} approved</span>
                    @endif
                    @if($stageAssignments->contains(fn ($a) => $a->reassigned_from))
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-semibold bg-processing-100 text-processing-700">Reassigned</span>
                    @endif
                </p>

                @if($state === 'upcoming')
                    <p class="text-sm text-surface-400">
                        {{ $stage->stage_name === 'Final Approval' ? 'Opens after every other stage is approved' : 'Not yet reached' }}
                    </p>
                @elseif($totalSeats <= 1)
                    {{-- Single-seat stage: identical wording to before the
                         multi-approver redesign. --}}
                    <p class="text-sm text-surface-400">
                        @if($state === 'pending' && $soleAssignment->reassigned_from)
                            Reassigned from {{ $soleAssignment->reassignedFrom->full_name ?? 'a deactivated account' }}
                        @elseif($state === 'pending')
                            @php $lastOpened = $soleAssignment->approver ? \App\Models\DocumentReviewSession::openedAtFor($document->document_id, $soleAssignment->user_id) : null; @endphp
                            Awaiting decision{{ $soleAssignment->approver ? ' · ' . $soleAssignment->approver->full_name : '' }}
                            @if($soleAssignment->approver)
                                &middot;
                                @if($lastOpened)
                                    opened {{ $lastOpened->format('M j, Y, g:i A') }}
                                @else
                                    not yet reviewed
                                @endif
                            @endif
                        @elseif($soleAssignment->auto_approved)
                            Auto-approved by the system{{ $soleAssignment->approver ? ' — was assigned to ' . $soleAssignment->approver->full_name : '' }}
                        @else
                            {{ ucfirst($state) }}{{ $soleAssignment->approver ? ' by ' . $soleAssignment->approver->full_name : '' }}
                            @if($soleAssignment->acted_at) &middot; {{ $soleAssignment->acted_at->format('M j, Y, g:i A') }} @endif
                        @endif
                    </p>
                    @if($soleAssignment && $soleAssignment->comments)
                        <div class="mt-1 rounded-md bg-surface-50 border border-surface-100 px-2 py-1 text-sm">
                            <span class="font-semibold text-surface-600">{{ $soleAssignment->individual_status === 'rejected' ? 'Rejected Due To:' : 'Comments:' }}</span>
                            <span class="text-surface-600">{{ $soleAssignment->comments }}</span>
                        </div>
                    @endif
                    @if($soleAssignment && $soleAssignment->reassignment_reason)
                        <p class="text-sm text-surface-500 mt-0.5 italic">Reassignment reason: "{{ $soleAssignment->reassignment_reason }}"</p>
                    @endif
                    @if($soleAssignment && in_array($state, ['approved', 'auto_approved', 'rejected']) && $soleAssignment->approver)
                        @php $reviewSummary = $reviewSummaryFor($soleAssignment->user_id); @endphp
                        @if($reviewSummary)
                            <p class="text-sm text-surface-500 mt-0.5">Opened {{ $reviewSummary['opened']->format('M j, Y, g:i A') }}</p>
                            <p class="text-sm text-surface-500">
                                Reviewed {{ $reviewSummary['segments'] }} &mdash; {{ $reviewSummary['total'] }} total
                            </p>
                        @endif
                    @endif
                @else
                    {{-- Multi-seat stage: list who's still pending, then one
                         line per resolved seat with its own outcome/comment
                         — with several approvers, whose decision/comment is
                         whose is no longer implicit from context. --}}
                    @if($state === 'pending')
                        <p class="text-sm text-surface-400">
                            Awaiting decision from
                            @foreach($pendingSeats as $seat)
                                @php $lastOpened = \App\Models\DocumentReviewSession::openedAtFor($document->document_id, $seat->user_id); @endphp
                                <span class="text-surface-500">{{ $seat->approver->full_name ?? 'a deactivated account' }}</span>@if($seat->approver) ({{ $lastOpened ? 'opened ' . $lastOpened->format('M j, Y, g:i A') : 'not yet reviewed' }})@endif{{ !$loop->last ? ', ' : '' }}
                            @endforeach
                        </p>
                    @elseif($state === 'rejected')
                        <p class="text-sm text-surface-400">Rejected</p>
                    @else
                        <p class="text-sm text-surface-400">
                            {{ ucfirst($state) === 'Auto_approved' ? 'Auto-approved by the system' : 'Approved' }} — all {{ $totalSeats }} approvers signed off
                        </p>
                    @endif
                    @foreach($stageAssignments->whereIn('individual_status', ['approved', 'rejected', 'auto_approved']) as $seat)
                        <p class="text-sm text-surface-500 mt-0.5">
                            <span class="font-medium">{{ $seat->approver->full_name ?? 'a deactivated account' }}</span>:
                            {{ $seat->auto_approved ? 'auto-approved' : ucfirst($seat->individual_status) }}
                            @if($seat->acted_at) &middot; {{ $seat->acted_at->format('M j, Y, g:i A') }} @endif
                        </p>
                        @if($seat->comments)
                            <div class="mt-1 rounded-md bg-surface-50 border border-surface-100 px-2 py-1 text-sm">
                                <span class="font-semibold text-surface-600">{{ $seat->individual_status === 'rejected' ? 'Rejected Due To:' : 'Comments:' }}</span>
                                <span class="text-surface-600">{{ $seat->comments }}</span>
                            </div>
                        @endif
                        @if($seat->approver)
                            @php $reviewSummary = $reviewSummaryFor($seat->user_id); @endphp
                            @if($reviewSummary)
                                <p class="text-sm text-surface-500">Opened {{ $reviewSummary['opened']->format('M j, Y, g:i A') }}</p>
                                <p class="text-sm text-surface-500">
                                    Reviewed {{ $reviewSummary['segments'] }} &mdash; {{ $reviewSummary['total'] }} total
                                </p>
                            @endif
                        @endif
                    @endforeach
                    @foreach($stageAssignments->filter(fn ($a) => $a->reassignment_reason) as $seat)
                        <p class="text-sm text-surface-500 mt-0.5 italic">
                            {{ $seat->approver->full_name ?? 'Unknown' }} reassignment reason: "{{ $seat->reassignment_reason }}"
                        </p>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach
</div>
@endif
