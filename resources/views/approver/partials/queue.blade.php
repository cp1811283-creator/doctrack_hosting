{{--
    The approver's queue list — split out from dashboard.blade.php so the
    same markup can be rendered two ways: a normal full page load, and a
    fragment returned by ApprovalController::queueFragment() for the
    polling JS to swap in place (see the script block in dashboard.blade.php)
    without a full page reload.
--}}
@forelse($containers as $container)
    @php
        $docCount = $container->documents->count();
    @endphp
    <div class="review-container rounded-xl border {{ $container->is_batch ? 'border-primary-200 bg-primary-50/30' : 'border-surface-200 bg-white' }} shadow-card hover:shadow-card-hover transition-shadow overflow-hidden" data-document-titles="{{ strtolower($container->documents->map(fn ($stageAssignments) => $stageAssignments->first()->document->title)->implode('|')) }}">

        {{-- Batch header — only shown when 2+ documents were submitted together --}}
        @if($container->is_batch)
            <div class="px-6 py-3 bg-primary-100/60 border-b border-primary-200 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-md bg-primary-200/70 flex items-center justify-center flex-shrink-0">
                        <svg class="w-3.5 h-3.5 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </span>
                    <span class="text-sm font-semibold text-primary-800">Submitted Document/s - {{ $docCount }}</span>
                    <span class="text-sm text-surface-500">by {{ $container->originator->full_name }}</span>
                </div>
                <div class="text-sm font-medium {{ $container->due_date && $container->due_date->isPast() ? 'text-rejected-700' : 'text-surface-600' }}">
                    Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}
                </div>
            </div>
        @endif

        {{-- divide-surface-200 (not the near-invisible -100 shade, which
             was barely distinguishable from the white card behind it) so
             each document in a batch reads as a clearly separated block —
             see the "p-6" padding on each document's own wrapper below for
             the breathing room around this line. --}}
        <div class="divide-y-2 divide-surface-200">
            @foreach($container->documents as $documentId => $stageAssignments)
                @php
                    $doc = $stageAssignments->first()->document;

                    // Computed early, before the title row (not down by the
                    // action panel where this used to live) — the priority
                    // badge now sits right on the title line, aligned to the
                    // document name, so it's visible at a glance instead of
                    // buried at the bottom of the card.
                    //
                    // Only a still-pending seat is actionable — a document
                    // can also show up in this queue purely because it's
                    // waiting on OTHER approvers after this one already
                    // decided every seat they hold (see ApprovalController::
                    // resolvedButInFlightQueryFor()), in which case there's
                    // no priority to show at all.
                    $activeAssignment = $stageAssignments->where('individual_status', 'pending')
                        ->sortBy(fn ($a) => $a->stage->sequence_order)->first();

                    // Urgent/Normal/Low/Expired — driven by real remaining
                    // BUSINESS time before this seat's own SLA deadline
                    // (DocumentAssignment::urgencyRank()), not the
                    // document's overall due date, so the badge always
                    // agrees with the countdown shown in the action panel
                    // below.
                    $urgencyStyles = [
                        1 => ['Urgent', 'bg-rejected-50 text-rejected-700 ring-rejected-500/20'],
                        2 => ['Normal', 'bg-processing-50 text-processing-700 ring-processing-500/20'],
                        3 => ['Low', 'bg-surface-100 text-surface-600 ring-surface-300'],
                        4 => ['Expired', 'bg-rejected-100 text-rejected-800 ring-rejected-500/40'],
                    ];
                    [$pLabel, $pClass] = $activeAssignment ? $urgencyStyles[$activeAssignment->urgencyRank()] : [null, null];

                    // Majority-vote reject (Feature: one lone reject on a
                    // multi-approver stage no longer kills the document —
                    // see DocumentAssignment::stageRejectionStatus() and
                    // WorkflowService::completeStage()). Once enough OTHER
                    // seats on this stage have already approved that
                    // reject can no longer mathematically reach the
                    // threshold, Reject is taken off the table here
                    // rather than offered as a choice that can't do
                    // anything.
                    $voteStatus = $activeAssignment?->stageRejectionStatus();
                @endphp
                {{-- id="document-{id}" is the jump target a notification click scrolls
                     to (see NotificationController::markRead() and
                     ApprovalController::pageForDocument()) — scroll-mt-4 gives it a
                     little breathing room on landing, same as the ML Training page's
                     jump-nav targets. --}}
                <div id="document-{{ $documentId }}" class="p-6 scroll-mt-4">
                    @if(!$container->is_batch)
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-surface-400">Single-document request</span>
                            <span class="text-sm font-medium {{ $container->due_date && $container->due_date->isPast() ? 'text-rejected-700' : 'text-surface-600' }}">
                                Due {{ $container->due_date?->format('M j, Y g:i A') ?? '—' }}
                            </span>
                        </div>
                    @endif

                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="text-base font-semibold text-surface-900">{{ $doc->title }}</h3>
                        <span class="text-sm text-surface-400">· {{ $doc->ml_category }}</span>
                        @if($pLabel)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-sm font-semibold ring-1 ring-inset {{ $pClass }}">{{ $pLabel }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-surface-500 mb-2">
                        Submitted by {{ $doc->originator->full_name }}
                        @if($activeAssignment)
                            ·
                            <button type="button"
                               onclick="openReviewAndComment({{ $doc->document_id }}, 'Review &amp; Comment — {{ addslashes($doc->title) }}', '{{ route('approver.assignments.annotations', $activeAssignment) }}')"
                               class="text-primary-700 hover:underline font-medium">
                                Review &amp; Comment
                            </button>
                        @endif
                    </p>

                    <div class="mb-3">
                        <x-document-presence :document="$doc" :show-reviewers="false" />
                    </div>

                    {{-- Full stage pipeline for this document's category, so approvers can
                         see what already happened and what's still to come — not just
                         whichever single stage currently needs a decision. --}}
                    <div class="mb-4">
                        <x-workflow-stage-list :document="$doc" :show-due-date="false" />
                    </div>

                    {{-- Full stage pipeline above already highlights which of these belong
                         to you and which one is "Your turn" — this action targets only that
                         one. If you also hold a later stage on this same document, it stays
                         visible up there as "Up next" and becomes actionable here once this
                         one is resolved. --}}
                    @php
                        // $activeAssignment/$pLabel/$pClass are already
                        // computed above (see the title row's priority
                        // badge) — nothing further to do for those here.

                        // Real (business-hours-aware) countdown, not a raw
                        // wall-clock diff — see realSecondsRemaining()'s
                        // docblock for why: a diff that crosses an evening/
                        // weekend can look many times longer than the actual
                        // review time left, which is exactly what made the
                        // badge and the countdown look like they disagreed.
                        $realSecondsRemaining = $activeAssignment?->realSecondsRemaining();
                        $realRemainingLabel = null;
                        if ($realSecondsRemaining !== null) {
                            if ($realSecondsRemaining <= 0) {
                                $realRemainingLabel = 'expired';
                            } else {
                                $rh = intdiv($realSecondsRemaining, 3600);
                                $rm = intdiv($realSecondsRemaining % 3600, 60);
                                $realRemainingLabel = $rh > 0 ? "{$rh}h {$rm}m remaining" : "{$rm}m remaining";
                            }
                        }

                        // Minimum-review-time gate (config('review.min_review_seconds'))
                        // — computed here purely for the client-side countdown/
                        // disabled-button UX; ApprovalController::decide() is the
                        // real, authoritative enforcement regardless of what this
                        // shows. Already-reviewed-enough (e.g. returning after a
                        // past session) renders with 0 remaining, buttons enabled
                        // from the start — no need to reopen the viewer.
                        $reviewSecondsRemaining = $activeAssignment
                            ? max(0, config('review.min_review_seconds', 10) - \App\Models\DocumentReviewSession::secondsSpentSoFar($doc->document_id, auth()->id()))
                            : 0;

                        // Business-hours restriction (Admin toggle, off by
                        // default — see SystemSetting/ApprovalController::
                        // requireBusinessHoursIfEnforced()). $businessHoursEnforced/
                        // $isWithinBusinessHours are supplied by both
                        // ApprovalController::dashboard() and refresh(), so a
                        // live-swapped fragment can never disagree with a full
                        // page load about whether this is currently blocking.
                        $outsideBusinessHoursBlocked = ($businessHoursEnforced ?? false) && !($isWithinBusinessHours ?? true);

                        // Distinct from a genuine decision (confirmed 2026-10-03,
                        // a real reported bug): "Your decision is recorded" is
                        // flatly wrong when this approver's own seat was auto-
                        // approved because they missed the deadline — they
                        // never decided anything, the system did. Every
                        // co-approver on the SAME stage shares this exact same
                        // deadline (see WorkflowService::assignStage()'s
                        // docblock), so if this seat auto-approved, every
                        // sibling seat on that stage did too, at the same
                        // instant — there's no "some of them might still make
                        // it" to soften the message with, which is why this
                        // case drops the "still waiting on other approvers"
                        // line entirely instead of keeping it like the
                        // genuine-decision case below does.
                        $wasAutoApproved = $stageAssignments->contains('auto_approved', true);
                    @endphp
                    @if(!$activeAssignment)
                        @if($wasAutoApproved)
                            <div class="flex items-center gap-3 rounded-xl border border-processing-200 bg-processing-50/40 p-4">
                                <span class="w-7 h-7 rounded-full bg-processing-100 text-processing-700 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                                </span>
                                <p class="text-sm text-surface-600">
                                    <span class="font-medium text-processing-700">Auto-approved — you missed this deadline.</span>
                                </p>
                            </div>
                        @else
                            <div class="flex items-center gap-3 rounded-xl border border-approved-200 bg-approved-50/40 p-4">
                                <span class="w-7 h-7 rounded-full bg-approved-100 text-approved-700 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </span>
                                <p class="text-sm text-surface-600">
                                    <span class="font-medium text-approved-700">Your decision is recorded.</span>
                                    Still waiting on other approvers before this document is finalized — see the stage list above for who's left.
                                </p>
                            </div>
                        @endif
                    @else
                    <div class="flex flex-col sm:flex-row sm:items-center gap-4 rounded-xl border border-primary-200 bg-primary-50/40 p-4 shadow-sm">
                        <div class="flex-1 min-w-0">
                            {{-- Priority badge now lives up on the title row
                                 (aligned to the document name), not repeated
                                 here. --}}
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-sm text-surface-500 font-medium">Stage: {{ $activeAssignment->stage->stage_name }}</span>
                            </div>
                            <p class="text-sm text-surface-400">
                                SLA expires
                                <span class="font-semibold text-surface-600">{{ $activeAssignment->sla_expires_at?->format('M j, Y, g:i A') ?? '—' }}</span>
                                @if($realSecondsRemaining !== null)
                                    {{-- Ticks live via __docTrackUpdateRealRemaining() in layouts/app.blade.php
                                         — server-rendered text below is the starting value, immediately
                                         taken over by JS on load, same pattern the data-live-time elements
                                         elsewhere already use. --}}
                                    <span class="font-semibold {{ $realSecondsRemaining < 3600 ? 'text-rejected-700' : 'text-surface-600' }}"
                                          data-real-remaining="{{ max(0, $realSecondsRemaining) }}"
                                          data-live-urgent-under="3600">
                                        ({{ $realRemainingLabel }})
                                    </span>
                                @endif
                                @if(!($isWithinBusinessHours ?? true))
                                    <span class="text-xs text-surface-400">⏸ Paused (outside business hours)</span>
                                @endif
                            </p>
                            @if($outsideBusinessHoursBlocked)
                                <p class="text-sm text-processing-700 font-medium mt-1">
                                    Decisions are currently restricted to business hours (9 AM–5 PM, Mon–Sat) — Approve/Reject will unlock when the next working window opens.
                                </p>
                            @elseif($voteStatus && !$voteStatus['rejectStillPossible'])
                                <p class="text-sm text-processing-700 font-medium mt-1">
                                    This stage already has majority approval ({{ $voteStatus['approved'] }} of {{ $voteStatus['total'] }}) — it can no longer be rejected. You can still approve it, or flag a specific concern with Request Revision.
                                </p>
                            @endif
                        </div>

                            <form method="POST" action="{{ route('approver.assignments.decide', $activeAssignment) }}"
                                class="review-decide-form flex flex-col sm:w-64 gap-2"
                                data-document-id="{{ $doc->document_id }}"
                                data-review-remaining="{{ $reviewSecondsRemaining }}"
                                data-business-hours-blocked="{{ $outsideBusinessHoursBlocked ? '1' : '0' }}">
                                @csrf
                                {{-- One shared textarea serves both Approve and Reject — comments stay
                                     optional for Approve, but rejecting with no explanation leaves the
                                     originator nothing to act on when they resubmit (see
                                     ApprovalController::decide()'s matching server-side rule), so each
                                     button toggles .required on click rather than the field being
                                     unconditionally required or optional. --}}
                                <textarea name="comments" rows="1" placeholder="Comments (required if rejecting)…"
                                    class="w-full rounded-lg border-surface-300 text-sm focus:border-primary-500 focus:ring-primary-500 px-3 py-2"></textarea>
                                @if($reviewSecondsRemaining > 0)
                                    <p class="review-countdown-label text-xs text-processing-700 font-medium">
                                        Open "Review &amp; Comment" above to begin your review — {{ $reviewSecondsRemaining }}s needed before you can decide.
                                    </p>
                                @endif
                                <div class="flex gap-2">
                                    <button type="submit" name="decision" value="approved"
                                        {{ ($reviewSecondsRemaining > 0 || $outsideBusinessHoursBlocked) ? 'disabled' : '' }}
                                        onclick="this.form.querySelector('textarea[name=comments]').required = false"
                                        class="review-decide-btn flex-1 bg-gradient-to-b from-approved-500 to-approved-600 hover:from-approved-600 hover:to-approved-700 text-white text-sm font-semibold py-2 rounded-lg shadow-sm transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:from-approved-500 disabled:hover:to-approved-600">
                                        Approve
                                    </button>
                                    <button type="submit" name="decision" value="rejected"
                                        {{ ($reviewSecondsRemaining > 0 || $outsideBusinessHoursBlocked || ($voteStatus && !$voteStatus['rejectStillPossible'])) ? 'disabled' : '' }}
                                        @if($voteStatus && !$voteStatus['rejectStillPossible'])
                                            title="This stage already has majority approval — it can no longer be rejected."
                                            data-permanently-disabled="1"
                                        @endif
                                        onclick="this.form.querySelector('textarea[name=comments]').required = true"
                                        class="review-decide-btn flex-1 bg-gradient-to-b from-rejected-500 to-rejected-600 hover:from-rejected-600 hover:to-rejected-700 text-white text-sm font-semibold py-2 rounded-lg shadow-sm transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:from-rejected-500 disabled:hover:to-rejected-600">
                                        Reject
                                    </button>
                                </div>
                            </form>
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
        <p class="text-base text-surface-500">
            @if(request('document') || request('priority'))
                No pending documents match these filters.
            @else
                Your queue is clear — no pending documents right now.
            @endif
        </p>
    </div>
@endforelse
<div id="review-no-matches" class="hidden bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
    <p class="text-base text-surface-500">No documents on this page match "<span id="review-no-matches-term"></span>". Press Enter to search every page.</p>
</div>

@if($containers->hasPages())
    <div class="pt-2">{{ $containers->links() }}</div>
@endif
