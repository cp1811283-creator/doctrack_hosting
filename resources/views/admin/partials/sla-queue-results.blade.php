{{--
    Auto-Approval Review results — split out from sla_queue.blade.php so
    the same markup can be rendered two ways: a normal full page load, and
    a fragment returned by AdminController::slaQueueRefresh() for the
    live-poll JS to swap in place, without a full page reload.

    Exclusively the auto-approved review queue now — a stage with no
    eligible approver auto-approves the instant ITS OWN (Admin-fallback)
    deadline passes too (see SlaService::escalateNeedsApprover()), same as
    a real approver's miss, so there's no more separate "escalated,
    waiting on Admin" section here. Each row's origin badge (below) is
    what tells the two cases apart now.
--}}
@forelse($reviewContainers as $container)
    @php $doc = $container->document; @endphp
    @php
        // Earliest review_due_at across this document's auto-approved
        // stages — a soft marker only (see SlaService::autoApproveOne()),
        // shown so Admin can see at a glance whether they're about to log
        // a late review against themselves, not just after the fact.
        $reviewDueAt = $container->assignments->pluck('review_due_at')->filter()->min();
        $reviewOverdue = $reviewDueAt && $reviewDueAt->isPast();
    @endphp
    <div id="review-doc-{{ $doc->document_id }}" class="rounded-xl border {{ $reviewOverdue ? 'border-rejected-500/30' : 'border-processing-500/20' }} bg-white shadow-card overflow-hidden mb-4">
        <div class="p-6">
            <div class="flex items-start justify-between gap-3 mb-1">
                <h3 class="text-sm font-semibold text-surface-900">{{ $doc->title }}</h3>
                {{-- Both branches are business-hours-aware now — a future
                     deadline's "remaining" via data-real-remaining (counts
                     down, only during business hours), and an already-
                     missed deadline's "ago" via data-real-elapsed (counts
                     UP, only during business hours) — see
                     __docTrackUpdateRealRemaining()/__docTrackUpdateRealElapsed()
                     in layouts/app.blade.php. Both review_due_at
                     (SlaService::reviewDeadlineFor()) and this "how overdue"
                     readout are measured in the same unit (working time),
                     so the on-screen numbers never imply urgency — or
                     backlog — that isn't actually accruing outside business
                     hours. The "⏸ Paused" label only needs to appear once
                     here (not repeated on every line further down this
                     card) since it applies to the whole card at once. --}}
                @if($reviewDueAt)
                    <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold
                        {{ $reviewOverdue ? 'bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20' : 'bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20' }}">
                        @if($reviewOverdue)
                            @php
                                $reviewOverdueSeconds = $businessHours
                                    ->businessSecondsRemaining($reviewDueAt, now());
                                $roh = intdiv($reviewOverdueSeconds, 3600);
                                $rom = intdiv($reviewOverdueSeconds % 3600, 60);
                                $reviewOverdueLabel = $roh > 0 ? "{$roh}h {$rom}m ago" : "{$rom}m ago";
                            @endphp
                            ⚠ Review overdue since {{ $reviewDueAt->format('M j, g:i A') }}
                            <span data-real-elapsed="{{ $reviewOverdueSeconds }}">({{ $reviewOverdueLabel }})</span>
                        @else
                            @php
                                $reviewRealSecondsRemaining = $businessHours
                                    ->businessSecondsRemaining(now(), $reviewDueAt);
                                if ($reviewRealSecondsRemaining <= 0) {
                                    $reviewRealRemainingLabel = 'expired';
                                } else {
                                    $rrh = intdiv($reviewRealSecondsRemaining, 3600);
                                    $rrm = intdiv($reviewRealSecondsRemaining % 3600, 60);
                                    $reviewRealRemainingLabel = $rrh > 0 ? "{$rrh}h {$rrm}m remaining" : "{$rrm}m remaining";
                                }
                            @endphp
                            Review by {{ $reviewDueAt->format('M j, g:i A') }}
                            <span data-real-remaining="{{ max(0, $reviewRealSecondsRemaining) }}">({{ $reviewRealRemainingLabel }})</span>
                        @endif
                        @if(!($isWithinBusinessHours ?? true))
                            <span class="text-[10px] text-surface-400">⏸ Paused (outside business hours)</span>
                        @endif
                    </span>
                @endif
            </div>
            <p class="text-xs text-surface-500 mb-3">
                Category: {{ $doc->ml_category }} &middot;
                Uploaded {{ $doc->upload_date?->format('M j, Y g:i A') ?? '—' }} &middot;
                Due {{ $doc->due_date?->format('M j, Y g:i A') ?? '—' }} &middot;
                <button type="button"
                    onclick="openDocumentViewer('{{ route('documents.file', $doc) }}', '{{ $doc->mime_type }}', '{{ addslashes($doc->original_filename ?? $doc->title) }}', {{ $doc->document_id }})"
                    class="text-primary-700 hover:underline font-medium">View original file</button>
            </p>

            <div class="mb-4">
                <x-workflow-stage-list :document="$doc" :show-due-date="false" :business-hours="$businessHours ?? null" />
            </div>

            <ul class="mb-4 divide-y divide-surface-100 border border-surface-100 rounded-lg overflow-hidden">
                {{-- Grouped by stage — a stage with multiple eligible-approver
                     seats (see WorkflowService::assignStage()) used to print
                     its own name once per seat here; group first so a 2-seat
                     stage shows its name once, with each seat nested
                     underneath, matching workflow-stage-list.blade.php's
                     established per-stage grouping above. --}}
                @foreach($container->assignments->groupBy('stage_id') as $stageAssignments)
                    @php
                        $stage = $stageAssignments->first()->stage;
                        // Which reason this came from — a null user_id
                        // means nobody was ever eligible for this seat, so
                        // it was auto-approved immediately (see
                        // WorkflowService::assignStage()); otherwise a real
                        // approver missed their own window. A stage's seats
                        // can mix both (e.g. one real approver missed, the
                        // other seat had nobody eligible), so show whichever
                        // apply once next to the stage name instead of
                        // repeating per seat below.
                        $hasApproverMiss = $stageAssignments->contains(fn ($a) => $a->user_id !== null);
                        $hasNoEligibleApprover = $stageAssignments->contains(fn ($a) => $a->user_id === null);
                    @endphp
                    <li class="px-4 py-2.5 bg-surface-50/50">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-xs font-medium text-surface-800">{{ $stage->stage_name }}</p>
                            @if($hasNoEligibleApprover)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-rejected-50 text-rejected-700 ring-1 ring-inset ring-rejected-500/20">No Eligible Approver</span>
                            @endif
                            @if($hasApproverMiss)
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-processing-50 text-processing-700 ring-1 ring-inset ring-processing-500/20">Missed by Approver</span>
                            @endif
                        </div>
                        <div class="space-y-1.5">
                            @foreach($stageAssignments as $reviewAssignment)
                                <p class="text-xs text-surface-500">
                                    @if($reviewAssignment->user_id === null)
                                        {{-- No real deadline was ever actually
                                             reached here — the seat resolved
                                             at routing time, before its own
                                             sla_expires_at (still stored, but
                                             irrelevant now) could ever be
                                             "violated". --}}
                                        No eligible approver at routing time &middot;
                                    @else
                                        @php
                                            $violatedElapsed = $reviewAssignment->sla_expires_at
                                                ? $businessHours->businessSecondsRemaining($reviewAssignment->sla_expires_at, now())
                                                : 0;
                                            $veh = intdiv($violatedElapsed, 3600);
                                            $vem = intdiv($violatedElapsed % 3600, 60);
                                            $violatedElapsedLabel = $veh > 0 ? "{$veh}h {$vem}m ago" : "{$vem}m ago";
                                        @endphp
                                        Was assigned to: {{ $reviewAssignment->approver->full_name ?? 'a deactivated account' }} &middot;
                                        SLA violated {{ optional($reviewAssignment->sla_expires_at)->format('M j, Y g:i A') }} <span data-real-elapsed="{{ $violatedElapsed }}">({{ $violatedElapsedLabel }})</span> &middot;
                                    @endif
                                    @php
                                        $actedElapsed = $reviewAssignment->acted_at
                                            ? $businessHours->businessSecondsRemaining($reviewAssignment->acted_at, now())
                                            : 0;
                                        $aeh = intdiv($actedElapsed, 3600);
                                        $aem = intdiv($actedElapsed % 3600, 60);
                                        $actedElapsedLabel = $aeh > 0 ? "{$aeh}h {$aem}m ago" : "{$aem}m ago";
                                    @endphp
                                    Auto-approved {{ optional($reviewAssignment->acted_at)->format('M j, Y g:i A') }} <span data-real-elapsed="{{ $actedElapsed }}">({{ $actedElapsedLabel }})</span>
                                </p>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('admin.sla.review', $doc) }}" class="space-y-3">
                @csrf
                <textarea name="note" rows="2" placeholder="Note (required if disputing)…"
                    class="w-full rounded-lg border-surface-300 text-xs focus:border-primary-500 focus:ring-primary-500 px-4 py-3"></textarea>
                <div class="flex justify-end gap-3">
                    <button type="submit" name="outcome" value="confirmed"
                        class="bg-approved-500 hover:bg-approved-700 text-white text-xs font-semibold px-6 py-2.5 rounded-lg transition-colors">
                        Confirm
                    </button>
                    <button type="submit" name="outcome" value="disputed"
                        class="bg-rejected-500 hover:bg-rejected-700 text-white text-xs font-semibold px-6 py-2.5 rounded-lg transition-colors">
                        Dispute
                    </button>
                </div>
            </form>
        </div>
    </div>
@empty
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
        <p class="text-sm text-surface-500">Nothing is currently awaiting review — everything is on schedule.</p>
    </div>
@endforelse

@if($reviewContainers->hasPages())
    <div class="pt-4">{{ $reviewContainers->links() }}</div>
@endif
