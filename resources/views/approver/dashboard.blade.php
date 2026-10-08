@extends('layouts.app')
@section('title', 'Review Queue')
@section('page-title', 'Review Queue')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-xl shadow-card border border-surface-200 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[140px]">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" id="document-search" name="document" value="{{ request('document') }}"
                    placeholder="Search document" autocomplete="off"
                    class="w-full rounded-lg border-surface-300 text-sm pl-9 pr-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
            <select name="priority" onchange="this.form.submit()" class="rounded-lg border-surface-300 text-xs pl-3 pr-8 py-2">
                <option value="">All Priorities</option>
                @foreach(['Urgent', 'Normal', 'Low', 'Expired'] as $p)
                    <option value="{{ $p }}" {{ request('priority') === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
            <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg shadow-sm transition-colors">Filter</button>
            @if(request('document') || request('priority'))
                <a href="{{ route('approver.dashboard') }}" class="text-xs font-medium text-surface-500 hover:underline">Clear</a>
            @endif
        </form>
    </div>

    {{-- Tiny, auto-fading acknowledgment that a live update just happened —
         intentionally not a banner requiring a click; see the JS below for
         why (feels live, not "obviously refreshed"). Deliberately generic
         copy, not "N new" — this fires from two different triggers (an
         instant WebSocket push or the slow fallback poll) whose payloads
         don't share the same shape, so there isn't always a reliable count
         to report. --}}
    <div id="live-update-toast" class="hidden text-xs text-approved-700 font-medium transition-opacity duration-700 flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <span id="live-update-toast-text">Queue updated</span>
    </div>

    <div id="review-queue" class="space-y-6" data-user-id="{{ auth()->id() }}" data-poll-url="{{ route('approver.assignments.poll') }}" data-refresh-url="{{ route('approver.assignments.refresh') }}" data-initial-count="{{ $initialPendingCount }}">
        @include('approver.partials.queue')
    </div>
</div>

<script>
    // Real-time, client-side filter over the containers already rendered on
    // this page — instant, no round trip. Pressing Enter still submits the
    // surrounding <form> normally, running the "document" filter
    // server-side (see ApprovalController::dashboard()) across every page.
    // Re-run after every live swap too (see below), since fresh markup
    // needs the same filter re-applied — otherwise a swap would silently
    // undo an active search.
    function applyDocumentFilter() {
        const input = document.getElementById('document-search');
        const containers = Array.from(document.querySelectorAll('.review-container'));
        const noMatches = document.getElementById('review-no-matches');
        const noMatchesTerm = document.getElementById('review-no-matches-term');
        if (!input || containers.length === 0 || !noMatches) return;

        const term = input.value.trim().toLowerCase();
        let visibleCount = 0;

        containers.forEach((el) => {
            const matches = term === '' || el.dataset.documentTitles.includes(term);
            el.classList.toggle('hidden', !matches);
            if (matches) visibleCount++;
        });

        const showNoMatches = term !== '' && visibleCount === 0;
        noMatches.classList.toggle('hidden', !showNoMatches);
        if (showNoMatches) noMatchesTerm.textContent = input.value.trim();
    }

    document.getElementById('document-search')?.addEventListener('input', applyDocumentFilter);

    // Live-updates the queue without a full page reload — instant via
    // Reverb (see startLiveChannel in resources/js/app.js) when a new
    // assignment is routed to this approver, a co-approver decides their
    // own seat on a shared stage, or a co-approver opens/closes the
    // viewer (see DocumentAssignment::booted() and
    // DocumentReviewSession::booted()) — the "N of M approved" progress,
    // "opened X ago" / "Reviewed ... total" lines all depend on someone
    // else's action, not just this approver's own. The slow poll behind
    // it is only a fallback in case the WebSocket connection is down.
    //
    // Wrapped in DOMContentLoaded, not a bare IIFE: this is a plain inline
    // script, which runs immediately as the browser parses this point in
    // the page — but app.js (which defines startLiveChannel/startLivePoll)
    // is loaded via the Vite-injected module script tag in the layout's
    // <head>, and module scripts are always deferred until after the page
    // finishes parsing. Without this wrapper, this code runs BEFORE those
    // functions exist and silently throws, and nothing below the throw
    // ever executes — which is exactly why live updates looked completely
    // wired up but never actually ran.
    // Minimum-review-time countdown (Feature: can't Approve/Reject without
    // actually having opened the document first — see config('review.php')
    // and ApprovalController::decide()'s server-side gate, which is the
    // real enforcement regardless of what this shows). Server-rendered
    // data-review-remaining is the source of truth for "how long left";
    // this just ticks it down live once the viewer is actually open,
    // rather than making the approver refresh the page to see it update.
    let reviewCountdownIntervals = {};

    function clearReviewCountdowns() {
        Object.values(reviewCountdownIntervals).forEach(clearInterval);
        reviewCountdownIntervals = {};
    }

    // How many popups are currently open for a given document — a count,
    // not a plain flag, because "View original file" and "Review &
    // Comment" are two separate modals that can genuinely both be open at
    // once for the same document (see openReviewAndComment()'s own
    // comment). startReviewCountdown() below refuses to tick unless this
    // says a popup is actually open — see its own comment for why that
    // check has to live THERE, not just at the open/close event sites.
    let openReviewDocumentCounts = {};

    function markReviewDocumentOpened(documentId) {
        openReviewDocumentCounts[documentId] = (openReviewDocumentCounts[documentId] || 0) + 1;
    }

    function markReviewDocumentClosed(documentId) {
        if (!openReviewDocumentCounts[documentId]) return;
        openReviewDocumentCounts[documentId] -= 1;
        if (openReviewDocumentCounts[documentId] <= 0) delete openReviewDocumentCounts[documentId];
    }

    function startReviewCountdown(documentId) {
        if (reviewCountdownIntervals[documentId]) return; // already ticking

        // This is the actual fix for the countdown ticking down in the
        // background with no popup open at all: it's not enough to stop
        // ticking when a popup closes (see documentviewer:closed below) —
        // the live queue refresh (poll or Reverb broadcast) restarts a
        // countdown for EVERY form with remaining time left on EVERY swap,
        // with no idea whether that document's popup is still open. That
        // restart call is exactly what kept resurrecting the countdown a
        // few seconds after close and letting it finish counting down in
        // the background anyway. Refusing to start here — regardless of
        // which of the several call sites asked — is the one place that
        // guards all of them at once.
        if (!openReviewDocumentCounts[documentId]) return;

        const initialForm = document.querySelector(`.review-decide-form[data-document-id="${documentId}"]`);
        if (!initialForm) return;

        let remaining = parseInt(initialForm.dataset.reviewRemaining, 10);
        if (!remaining || remaining <= 0) return;

        // Re-queries the form/label/buttons fresh on every tick, rather than
        // holding onto the node references above — this queue lives-refreshes
        // itself on ANY approver's activity anywhere (a broad 'approvers'
        // channel, see startLiveChannel('approvers', ...) further down), and
        // opening either "View original file" or "Review & Comment" is
        // itself one such activity. That refresh replaces this exact form
        // element with a freshly server-rendered one; without re-querying,
        // this interval kept ticking down a detached copy nobody could see
        // while the real, visible form's countdown silently froze at
        // whatever the refresh happened to render, permanently stuck
        // disabled until a manual page reload.
        reviewCountdownIntervals[documentId] = setInterval(() => {
            remaining -= 1;
            const form = document.querySelector(`.review-decide-form[data-document-id="${documentId}"]`);
            if (!form) {
                // Left the queue entirely (e.g. someone else's decision
                // just finalized this document) — nothing left to tick.
                clearInterval(reviewCountdownIntervals[documentId]);
                delete reviewCountdownIntervals[documentId];
                return;
            }
            form.dataset.reviewRemaining = remaining;
            const label = form.querySelector('.review-countdown-label');
            const buttons = form.querySelectorAll('.review-decide-btn');

            if (remaining <= 0) {
                clearInterval(reviewCountdownIntervals[documentId]);
                delete reviewCountdownIntervals[documentId];
                if (label) label.remove();
                // The review-time floor is met, but a separate gate (Admin's
                // business-hours toggle, see requireBusinessHoursIfEnforced())
                // can still be the reason these need to stay disabled — the
                // "SLA expires" note above already explains that case, so
                // this doesn't need its own countdown of its own.
                if (form.dataset.businessHoursBlocked !== '1') {
                    // Skip Reject when it's permanently disabled for a
                    // different reason (majority-vote already reached the
                    // point where reject can no longer take effect — see
                    // DocumentAssignment::stageRejectionStatus()) — the
                    // review-time floor clearing doesn't change that.
                    buttons.forEach((btn) => {
                        if (btn.dataset.permanentlyDisabled !== '1') btn.disabled = false;
                    });
                }
            } else if (label) {
                label.textContent = `Reviewing… ${remaining}s remaining before you can decide.`;
            }
        }, 1000);
    }

    // Added once, on `window` — never torn down by a live swap (only the
    // #review-queue subtree gets replaced), so this doesn't need to be
    // re-attached in onSwap the way DOM-scoped listeners would.
    window.addEventListener('documentviewer:opened', (e) => {
        markReviewDocumentOpened(e.detail.documentId);
        startReviewCountdown(e.detail.documentId);
    });

    // Closing either popup (document-viewer-modal.blade.php's
    // closeDocumentViewer() or kpi-drilldown-modal.blade.php's
    // closeKpiDrilldown()) ends the REAL review session server-side, but
    // without this, the countdown above kept ticking down in the browser
    // regardless — a plain wall-clock stopwatch from "opened", with no
    // idea the popup (and the session behind it) had already closed. That
    // let it reach 0 and enable Approve/Reject even after someone closed
    // the viewer well before the real minimum review time — clicking
    // still correctly got rejected server-side (ApprovalController::
    // decide() re-checks the real session time independently), but the
    // button itself lied about being usable.
    //
    // Two things happen on close, both necessary: stopReviewCountdown()
    // freezes whatever interval is ticking RIGHT NOW (so
    // form.dataset.reviewRemaining stops at the real remaining figure
    // instead of continuing past it), and markReviewDocumentClosed()
    // records that nothing is open for this document anymore, so the next
    // live-refresh's blanket "restart every countdown with time left"
    // pass (see the two onSwap handlers below) can't resurrect a fresh
    // countdown for it either — startReviewCountdown() checks exactly
    // that before it will start ticking at all.
    function stopReviewCountdown(documentId) {
        if (reviewCountdownIntervals[documentId]) {
            clearInterval(reviewCountdownIntervals[documentId]);
            delete reviewCountdownIntervals[documentId];
        }
    }

    window.addEventListener('documentviewer:closed', (e) => {
        markReviewDocumentClosed(e.detail.documentId);
        stopReviewCountdown(e.detail.documentId);
    });

    // "Review & Comment" feature parity with "View original file" (Feature:
    // in practice this is the popup approvers actually use to decide, so
    // it needs the same minimum-review-time gate + "who's reviewing now"
    // presence icon the file viewer already has — see annotations-panel.
    // blade.php's own header markup for the presence icon/print button,
    // and ApprovalController::annotationsPanel() for the session this
    // polling keeps alive). A separate, independently-scoped poller from
    // document-viewer-modal.blade.php's own __startPresencePoll() —
    // reusing that one directly would mean whichever popup opened second
    // silently cancels the first one's poll (its own __stopPresencePoll()
    // runs at the top of every start), and both popups can genuinely be
    // open at once. __escapeHtmlAttr() is reused as-is from that file,
    // since it's a plain global function with nothing document-viewer-
    // specific about it.
    let __annotationPresencePollTimer = null;
    let __annotationPresenceDocumentId = null;

    function __annotationRenderPresence(viewers) {
        const el = document.getElementById('annotation-presence');
        if (!el) return;
        if (!viewers || viewers.length === 0) {
            el.classList.add('hidden');
            el.removeAttribute('open');
            return;
        }
        el.classList.remove('hidden');

        document.getElementById('annotation-presence-count').textContent = viewers.length;

        const avatars = viewers.slice(0, 4).map((v) => {
            const initial = (v.name || '?').charAt(0).toUpperCase();
            return `<span class="w-5 h-5 rounded-full bg-approved-500 text-white text-[9px] font-bold flex items-center justify-center ring-2 ring-white shadow-sm" title="${__escapeHtmlAttr(v.name)} is currently reviewing">${__escapeHtmlAttr(initial)}</span>`;
        }).join('');
        document.getElementById('annotation-presence-avatars').innerHTML = avatars;

        const names = viewers.map((v) => `<div class="px-3 py-1.5 text-xs">
            <p class="font-medium text-surface-800">${__escapeHtmlAttr(v.name)}</p>
            <p class="text-[10px] text-surface-400">${__escapeHtmlAttr(v.role)}${v.category ? ' &middot; ' + __escapeHtmlAttr(v.category) : ''}</p>
        </div>`).join('');
        document.getElementById('annotation-presence-names').innerHTML = names;
    }

    function __annotationPollPresence(url) {
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => __annotationRenderPresence(data.viewers))
            .catch(() => {});
    }

    function __annotationSendPresenceLeave() {
        if (!__annotationPresenceDocumentId) return;
        const url = @json(route('documents.presence.leave', ['document' => '__ID__'])).replace('__ID__', __annotationPresenceDocumentId);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(url, {
            method: 'POST',
            keepalive: true,
            headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        }).catch(() => {});
        __annotationPresenceDocumentId = null;
    }

    function __annotationStopPresencePoll() {
        if (__annotationPresencePollTimer) {
            clearInterval(__annotationPresencePollTimer);
            __annotationPresencePollTimer = null;
        }
        __annotationSendPresenceLeave();
        const el = document.getElementById('annotation-presence');
        if (el) {
            el.classList.add('hidden');
            el.removeAttribute('open');
        }
    }

    function __annotationStartPresencePoll(documentId) {
        __annotationStopPresencePoll();
        if (!documentId) return;
        __annotationPresenceDocumentId = documentId;

        const url = @json(route('documents.presence', ['document' => '__ID__'])).replace('__ID__', documentId);
        __annotationPollPresence(url);
        __annotationPresencePollTimer = setInterval(() => __annotationPollPresence(url), 8000);
    }

    // Covers closing the browser tab/navigating away without ever
    // clicking the X — closeKpiDrilldown() alone can't catch that. Mirrors
    // document-viewer-modal.blade.php's identical pagehide listener.
    window.addEventListener('pagehide', __annotationSendPresenceLeave);

    // Called from queue.blade.php in place of a bare openKpiDrilldown() —
    // starts the same countdown/presence tracking "View original file"
    // triggers, on top of the normal drilldown-modal fetch.
    function openReviewAndComment(documentId, label, url) {
        openKpiDrilldown('annotations', label, url);
        __annotationStartPresencePoll(documentId);
        if (documentId) {
            window.dispatchEvent(new CustomEvent('documentviewer:opened', { detail: { documentId } }));
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const queueEl = document.getElementById('review-queue');
        if (!queueEl) return;

        const toast = document.getElementById('live-update-toast');
        const toastText = document.getElementById('live-update-toast-text');

        // message/isError let the decide AJAX handler below reuse this same
        // toast for its own result — "Decision recorded: Approved." in the
        // normal success color, or a genuine error (e.g. the review-time
        // gate) in red — instead of a plain generic "Queue updated" every
        // time.
        const showToast = (message, isError = false) => {
            if (toastText) toastText.textContent = message || 'Queue updated';
            toast.classList.toggle('text-approved-700', !isError);
            toast.classList.toggle('text-rejected-700', isError);
            toast.classList.remove('hidden');
            toast.style.opacity = '1';
            setTimeout(() => { toast.style.opacity = '0'; }, 2500);
            setTimeout(() => { toast.classList.add('hidden'); }, 3200);
        };

        const opts = {
            refreshUrl: queueEl.dataset.refreshUrl,
            target: queueEl,
            preserveQueryString: true,
            // If the approver is actively typing a comment (a textarea has
            // focus or unsaved text), skip this update rather than wiping
            // out whatever they were about to submit — the next poll cycle
            // (or the approver's own next action) will catch it up.
            isBusy: () => Array.from(document.querySelectorAll('#review-queue textarea')).some(
                (el) => el === document.activeElement || el.value.trim() !== ''
            ),
            onSwap: () => {
                applyDocumentFilter();
                showToast();
                // The fresh markup came with its own server-computed
                // data-review-remaining (already reflecting real elapsed
                // time on any still-open session) — any interval ticking
                // against the OLD, now-replaced form nodes is stale, so
                // clear those out. But RESTART a fresh countdown against
                // the new nodes rather than leaving it at that: opening
                // either "View original file" or "Review & Comment" opens
                // a review session, and opening a session is itself
                // exactly the kind of activity that broadcasts and
                // triggers this very refresh — so a swap fires almost
                // immediately after either popup starts its own
                // countdown. Without restarting here, that near-instant
                // swap would silently kill the ticking countdown, leaving
                // Approve/Reject stuck disabled with nothing counting
                // down until the approver manually reloads the page.
                clearReviewCountdowns();
                document.querySelectorAll('.review-decide-form[data-document-id]').forEach((form) => {
                    if (parseInt(form.dataset.reviewRemaining, 10) > 0) {
                        startReviewCountdown(Number(form.dataset.documentId));
                    }
                });
            },
        };

        startLiveChannel(`approver.${queueEl.dataset.userId}`, '.assignment.routed', opts);
        startLiveChannel(`approver.${queueEl.dataset.userId}`, '.document.review-activity', opts);
        // Originator revised a flagged document's text in place (Feature:
        // Request Revision — see WorkflowService::saveDocumentRevision(),
        // which already fires DocumentStatusChanged) — without this, an
        // approver with the queue open would keep seeing the stale
        // pre-revision text/annotations in "Review & Comment" until their
        // next background poll instead of right away. Reuses that
        // existing broadcast on the same broad 'approvers' channel as the
        // system-settings listener just below, rather than a new event —
        // same "broadcast broadly, let this page's own query filter it"
        // pattern already established there.
        startLiveChannel('approvers', '.document.status-changed', opts);
        // Admin's business-hours-restriction toggle (Workflow Config) — a
        // shared channel every approver listens on, not a per-user one,
        // since this setting affects everyone's queue at once. Without
        // this, someone with the queue already open would only pick up a
        // toggle flip on the next background poll instead of instantly.
        startLiveChannel('approvers', '.system-settings.changed', opts);
        startLivePoll({ ...opts, pollUrl: queueEl.dataset.pollUrl });

        // Approve/Reject, AJAX-ified (Feature: no more scroll-jump-to-top).
        // The form used to submit natively, which redirected back to this
        // page — a full navigation that always reloads scrolled to the top,
        // no matter where in a long queue the approver was. Intercepting
        // the submit and swapping just the queue fragment in place (same
        // applyLiveRefresh() the poll/channel updates above already use)
        // keeps the page — and the approver's scroll position — exactly
        // where it was.
        queueEl.addEventListener('submit', function (e) {
            const form = e.target.closest('.review-decide-form');
            if (!form) return;
            e.preventDefault();

            // Browser-native `required` validation (toggled per-button, see
            // the queue partial) already ran before this handler fires — a
            // blocked/invalid form never reaches here at all.
            fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form, e.submitter),
            })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Something went wrong — please try again.');
                    return data;
                })
                .then((data) => {
                    window.applyLiveRefresh({
                        refreshUrl: queueEl.dataset.refreshUrl,
                        target: queueEl,
                        preserveQueryString: true,
                        onSwap: () => {
                            applyDocumentFilter();
                            // Same restart-not-just-clear reasoning as the
                            // live-channel onSwap above — this refresh
                            // replaces the WHOLE queue, so any OTHER still-
                            // pending document's countdown needs to resume
                            // too, not just this one (which no longer needs
                            // one, having just been decided).
                            clearReviewCountdowns();
                            document.querySelectorAll('.review-decide-form[data-document-id]').forEach((f) => {
                                if (parseInt(f.dataset.reviewRemaining, 10) > 0) {
                                    startReviewCountdown(Number(f.dataset.documentId));
                                }
                            });
                            showToast(data.status);
                        },
                    });
                })
                .catch((err) => showToast(err.message, true));
        });

        // Request Revision (Feature: highlight a passage of the "Review &
        // Comment" panel's text, add a comment — see ApprovalController::
        // requestRevision()). That panel is fetched into the shared
        // openKpiDrilldown() modal (see components/kpi-drilldown-modal.
        // blade.php) via body.innerHTML, which means any <script> tag
        // inside the fetched fragment itself would NEVER run — browsers
        // don't execute scripts set that way. So this is delegated from
        // here instead, on #kpi-drilldown-body, a stable ancestor that
        // exists in the DOM before the fragment ever loads and survives
        // every later swap.
        const drilldownBody = document.getElementById('kpi-drilldown-body');
        if (drilldownBody) {
            let pendingRange = null;

            // Keeps an ALREADY-OPEN "Review & Comment" popup in sync too
            // (Feature: annotate/withdraw/revise, all realtime — see
            // WorkflowService's requestRevision()/withdrawAnnotation()/
            // saveDocumentRevision(), which all fire DocumentStatusChanged
            // on this same broadcast). The queue-level listener further up
            // this file only swaps #review-queue's own markup — the
            // popup's content is a separate fetch (annotationsPanel())
            // that wouldn't otherwise notice a push while it's already
            // open, so without this an approver had to close and reopen
            // it (or wait for the background poll) to see a co-approver's
            // new/withdrawn flag or the originator's saved revision.
            // refreshUrl is a function, not a fixed string, since WHICH
            // assignment's panel is open varies over time — see
            // applyLiveRefresh()'s support for that in app.js.
            startLiveChannel('approvers', '.document.status-changed', {
                target: drilldownBody,
                refreshUrl: () => document.getElementById('annotation-panel-root')?.dataset.refreshUrl,
                // Never yank the text out from under someone mid-comment OR
                // mid-selection — same reasoning as the queue-level isBusy
                // check above, just scoped to this popup. The selection
                // check matters just as much as the comment-box one: a
                // background swap replacing #annotation-text right as
                // someone finishes dragging a selection over it (or right
                // in the gap between mouseup and pendingRange actually
                // being set below) previously let a saved start/end offset
                // land on the wrong DOM/text state entirely — the exact bug
                // behind a flagged passage's highlight landing on the wrong
                // characters after several rapid flag/withdraw cycles.
                isBusy: () => {
                    const comment = document.getElementById('annotation-comment');
                    if (comment && (comment === document.activeElement || comment.value.trim() !== '')) return true;
                    if (pendingRange) return true;

                    const selection = window.getSelection();
                    const textEl = document.getElementById('annotation-text');
                    return !!selection && !selection.isCollapsed && !!textEl && textEl.contains(selection.anchorNode);
                },
                // Only refetch if the popup is actually showing THIS
                // document right now — 'approvers' is a broad, shared
                // channel (every approver's dashboard listens on it), not
                // one scoped to a single document.
                filter: (data) => {
                    const textEl = document.getElementById('annotation-text');
                    return !!textEl && Number(textEl.dataset.documentId) === Number(data.document_id);
                },
                // The fresh markup has no selection pending on it — an old
                // pendingRange (offsets into text that just got replaced)
                // would no longer point at anything meaningful.
                onSwap: () => { pendingRange = null; },
            });

            // Character offsets relative to #annotation-text's own text
            // content — same numbering the server computes against
            // DocumentRepository::ocr_text, since this element's rendered
            // text (segments concatenated back together) is exactly that
            // same string.
            function offsetsWithin(container, range) {
                const preSelection = document.createRange();
                preSelection.selectNodeContents(container);
                preSelection.setEnd(range.startContainer, range.startOffset);
                const start = preSelection.toString().length;
                return { start, end: start + range.toString().length, text: range.toString() };
            }

            drilldownBody.addEventListener('mouseup', function (e) {
                const container = e.target.closest('#annotation-text');
                if (!container) return;

                const selection = window.getSelection();
                if (!selection || selection.isCollapsed || selection.rangeCount === 0) return;
                const range = selection.getRangeAt(0);
                if (!container.contains(range.commonAncestorContainer)) return;

                const offsets = offsetsWithin(container, range);
                if (!offsets.text.trim()) return;

                pendingRange = offsets;
                const preview = document.getElementById('annotation-selected-preview');
                if (preview) preview.textContent = `"${offsets.text.length > 140 ? offsets.text.slice(0, 140) + '…' : offsets.text}"`;
                document.getElementById('annotation-form-area')?.classList.remove('hidden');
                document.getElementById('annotation-comment')?.focus();
            });

            drilldownBody.addEventListener('click', function (e) {
                const withdrawBtn = e.target.closest('[data-withdraw-annotation]');
                if (withdrawBtn) {
                    // Styled popup (see components/confirm-modal.blade.php),
                    // not the browser's own confirm() — that one can't be
                    // restyled and shows the raw hostname, which looked out
                    // of place next to the rest of this app's own UI.
                    openConfirmModal({
                        title: 'Withdraw this revision request?',
                        message: 'The originator will be notified either way.',
                        confirmLabel: 'Withdraw',
                        onConfirm: () => {
                            withdrawBtn.disabled = true;
                            fetch(withdrawBtn.dataset.withdrawUrl, {
                                method: 'POST',
                                headers: {
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                },
                            })
                                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                                .then(() => {
                                    const root = document.getElementById('annotation-panel-root');
                                    if (!root) return;
                                    // Same fetch-and-replace pattern the request-revision
                                    // submit handler below already uses — re-fetching
                                    // drops the withdrawn flag's highlight/row immediately
                                    // instead of waiting for the next live-sync tick.
                                    return fetch(root.dataset.refreshUrl, { headers: { Accept: 'text/html' } })
                                        .then((res) => res.text())
                                        .then((html) => { drilldownBody.innerHTML = html; });
                                })
                                .catch(() => {
                                    withdrawBtn.disabled = false;
                                    showToast("Couldn't withdraw that — please try again.", true);
                                });
                        },
                    });
                    return;
                }

                if (!e.target.closest('#annotation-cancel')) return;
                pendingRange = null;
                document.getElementById('annotation-form-area')?.classList.add('hidden');
                document.getElementById('annotation-form')?.reset();
                const status = document.getElementById('annotation-status');
                if (status) status.textContent = '';
            });

            drilldownBody.addEventListener('submit', function (e) {
                const form = e.target.closest('#annotation-form');
                if (!form) return;
                e.preventDefault();
                if (!pendingRange) return;

                const root = document.getElementById('annotation-panel-root');
                const status = document.getElementById('annotation-status');
                const commentField = document.getElementById('annotation-comment');
                if (!root || !status || !commentField) return;

                status.textContent = 'Sending…';
                status.className = 'text-sm mt-2 text-surface-500';

                fetch(root.dataset.requestRevisionUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        start_offset: pendingRange.start,
                        end_offset: pendingRange.end,
                        selected_text: pendingRange.text,
                        comment: commentField.value,
                    }),
                })
                    .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                    .then(() => {
                        pendingRange = null;
                        // Re-fetch the panel so the freshly-added highlight
                        // shows immediately, same fetch-and-replace pattern
                        // openKpiDrilldown() itself uses.
                        return fetch(root.dataset.refreshUrl, { headers: { Accept: 'text/html' } })
                            .then((res) => res.text())
                            .then((html) => { drilldownBody.innerHTML = html; });
                    })
                    .catch(() => {
                        status.textContent = "Couldn't send that — please try again.";
                        status.className = 'text-sm mt-2 text-rejected-700 font-medium';
                    });
            });
        }
    });
</script>
@endsection
