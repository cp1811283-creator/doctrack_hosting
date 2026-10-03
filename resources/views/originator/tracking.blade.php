@extends('layouts.app')
@section('title', 'Track Document')
@section('page-title', 'Document Tracking')

@section('content')
<div id="tracking-content"
    data-document-id="{{ $document->document_id }}"
    data-originator-id="{{ $document->originator_id }}"
    data-poll-url="{{ route('originator.documents.trackingPoll', $document) }}"
    data-refresh-url="{{ route('originator.documents.trackingRefresh', $document) }}">
    @include('originator.partials.tracking-content')
</div>

<script>
    // Live-updates this document's status/stages/audit trail without a
    // full page reload — instant via Reverb the moment any stage on THIS
    // document is decided or its overall status changes (not just full
    // approval/rejection — a single stage being approved mid-pipeline
    // updates the Approval Stages list here too); the slow poll behind it
    // is only a fallback in case the WebSocket connection is down. See
    // startLiveChannel()/startLivePoll() in resources/js/app.js, and the
    // matching comment in approver/dashboard.blade.php for why this is
    // wrapped in DOMContentLoaded rather than a bare IIFE.
    // Document Tracker's own scroll area (see tracking-content.blade.php)
    // is sized to exactly fill the space left over below the document
    // header card, so a long tracker scrolls internally instead of pushing
    // <main> — this app's real scroll container, see layouts/app.blade.php
    // — into scrolling the whole page. Measured live against <main>'s own
    // bottom edge rather than a fixed calc(), since the header card above
    // it varies in height (version badge, resubmit form, Imported/
    // Superseded notices, ...) — a fixed number would only be correct for
    // whichever card height happened to be on screen when it was written.
    function sizeDocumentTracker() {
        const scrollEl = document.getElementById('document-tracker-scroll');
        const mainEl = document.querySelector('main');
        if (!scrollEl || !mainEl) return;

        // <main>'s own bottom padding (p-4 sm:p-8) sits between its
        // content and its measured bottom edge — read live via
        // getComputedStyle rather than hardcoded, so this stays correct if
        // that padding class ever changes, instead of quietly drifting out
        // of sync again the way a flat buffer alone previously did (it
        // left the tracker ~24px too tall, just enough to force <main>
        // itself into scrolling on top of the tracker's own internal one).
        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - scrollEl.getBoundingClientRect().top;
        // Floor guard so a very tall header card never collapses this to
        // something unusably short.
        scrollEl.style.maxHeight = Math.max(available - 8, 200) + 'px';
    }

    // Same technique as sizeDocumentTracker() just above, pointed at the
    // RIGHT column instead (Approval Stages, plus Revision Requests once
    // there's anything open). That column used to have no height cap at
    // all — fine while it only ever held Approval Stages, but adding a
    // whole extra Revision Requests card underneath it (Feature: editable
    // document) could make it tall enough to outgrow the viewport, and
    // with no cap of its own <main> — the real page scroll container —
    // was the thing that ended up scrolling instead of just this column.
    function sizeTrackingRightColumn() {
        const columnEl = document.getElementById('tracking-right-column');
        const mainEl = document.querySelector('main');
        if (!columnEl || !mainEl) return;

        const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
        const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - columnEl.getBoundingClientRect().top;
        columnEl.style.maxHeight = Math.max(available, 200) + 'px';
    }

    function sizeTrackingLayout() {
        sizeDocumentTracker();
        sizeTrackingRightColumn();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const contentEl = document.getElementById('tracking-content');
        if (!contentEl) return;

        sizeTrackingLayout();
        window.addEventListener('resize', sizeTrackingLayout);

        const thisDocumentId = parseInt(contentEl.dataset.documentId, 10);

        // Feature: the revision-save button starts disabled and only
        // enables once the originator has actually changed something —
        // captures each editable surface's STARTING text (on load, and
        // again after every live swap, since a swap replaces the whole
        // fragment with fresh elements that have no captured state yet)
        // so later edits have something to compare against.
        function captureOriginalRevisionState() {
            const textarea = document.getElementById('revision-text-editor');
            if (textarea) {
                textarea.dataset.original = textarea.value;
            }
            document.getElementById('docx-revision-editor')?.querySelectorAll('[data-seg]').forEach((span) => {
                span.dataset.original = span.textContent;
            });
        }

        // Single source of truth for "has the originator actually changed
        // anything in the revision form yet" — re-computed fresh every
        // call (never a stored flag, so it can't ever get stuck one way),
        // reused by both the save-button enable/disable logic AND the
        // live-refresh isBusy guard just below.
        // Text changes only — checking a flag's checkbox is purely
        // navigation (jump to that passage, see the 'change' listener
        // below) and was WRONGLY counted here too, enabling the save
        // button the instant you checked a box just to go look at
        // something, before you'd actually revised anything at all.
        function hasUnsavedRevisionEdits() {
            const textarea = document.getElementById('revision-text-editor');
            if (textarea) {
                return textarea.value !== textarea.dataset.original;
            }

            const editor = document.getElementById('docx-revision-editor');
            if (!editor) return false;

            return Array.from(editor.querySelectorAll('[data-seg]'))
                .some((span) => span.textContent !== span.dataset.original);
        }

        function updateSaveRevisionButtonState() {
            const submitBtn = document.getElementById('revision-text-submit') || document.getElementById('docx-revision-submit');
            if (!submitBtn) return;
            submitBtn.disabled = !hasUnsavedRevisionEdits();
        }

        const opts = {
            refreshUrl: contentEl.dataset.refreshUrl,
            target: contentEl,
            // The originator's channel carries events for ALL of their
            // documents, not just this one — only react when the event is
            // actually about the document this page is showing.
            filter: (data) => data.document_id === thisDocumentId,
            // Without this, a routine live update (another approver's
            // activity, an unrelated status change — anything that
            // touches this document) arriving mid-edit would silently
            // replace the whole Revision Requests card with a fresh copy
            // from the server, discarding whatever the originator had
            // just typed and resetting the save button back to disabled —
            // confirmed as the actual cause of the save button appearing
            // "broken." Skipped entirely while there's an unsaved edit;
            // resumes on its own the moment that's no longer true (saved,
            // reverted, or the page reloads after a real save) since
            // hasUnsavedRevisionEdits() is checked live, not cached.
            isBusy: hasUnsavedRevisionEdits,
            // The live swap replaces #tracking-content's whole innerHTML
            // (new header card content, new tracker rows, and possibly a
            // Revision Requests card appearing/disappearing) — re-measure
            // both columns afterward, not just once on initial load, and
            // re-capture the freshly-swapped-in revision editor's own
            // starting state too.
            onSwap: () => {
                sizeTrackingLayout();
                captureOriginalRevisionState();
                updateSaveRevisionButtonState();
            },
        };

        captureOriginalRevisionState();
        updateSaveRevisionButtonState();

        // Re-evaluate on every keystroke/edit in either editable surface.
        contentEl.addEventListener('input', function (e) {
            if (e.target.closest('#revision-text-editor') || e.target.closest('#docx-revision-editor')) {
                updateSaveRevisionButtonState();
            }
        });

        // Subscribed to the document's actual owner's channel, not the
        // current viewer's own id — for the originator viewing their own
        // document these are the same person, but an Admin (or anyone else
        // permitted onto this page) viewing someone ELSE's document has a
        // different id from the owner, and the server only ever broadcasts
        // document.status-changed on the owner's channel (see
        // DocumentStatusChanged::broadcastOn()). Subscribing to the
        // viewer's own id there would silently never receive anything,
        // leaving that viewer stuck on the slow poll fallback only.
        startLiveChannel(`originator.${contentEl.dataset.originatorId}`, '.document.status-changed', opts);
        startLivePoll({ ...opts, pollUrl: contentEl.dataset.pollUrl });

        // Heads-up only, not authoritative — same check as the main
        // upload form's (see originator/dashboard.blade.php's matching
        // comment for the full reasoning). Delegated on #tracking-content
        // — the stable wrapper that survives every live swap above —
        // rather than bound directly to the input, which gets replaced
        // wholesale on every swap along with the rest of this fragment.
        const businessHoursConfig = JSON.parse(document.querySelector('meta[name="business-hours"]')?.content || 'null');

        function isWithinWorkingHours(date) {
            if (!businessHoursConfig || isNaN(date.getTime())) return true;
            if (!businessHoursConfig.workingDays.includes(date.getDay())) return false;

            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            if (businessHoursConfig.holidays.includes(`${y}-${m}-${d}`)) return false;

            const minutes = date.getHours() * 60 + date.getMinutes();
            return minutes >= businessHoursConfig.startMinutes && minutes < businessHoursConfig.endMinutes;
        }

        contentEl.addEventListener('change', function (e) {
            const input = e.target.closest('#resubmit-due-date');
            if (!input) return;
            const warning = contentEl.querySelector('#resubmit-due-date-warning');
            if (!warning) return;
            const valid = !input.value || isWithinWorkingHours(new Date(input.value));
            warning.classList.toggle('hidden', valid);
        });

        // Checking a "Revision Requests" flag (Feature: jump straight to
        // the exact flagged passage in the text below, instead of the
        // originator having to hunt for it themselves — a real problem
        // once the same short phrase appears more than once in a
        // document). Delegated on #tracking-content, not bound to the
        // checkboxes directly, for the same reason as every other
        // listener in this block — they get replaced wholesale on every
        // live swap. tracking-content.blade.php itself can't carry this
        // as an inline <script> for that same reason: a script tag inside
        // markup that later gets swapped in via innerHTML never runs.
        // Walks every text node inside $container in document order,
        // accumulating length, and returns a Range spanning [start, end)
        // in that SAME numbering — the inverse of how DocxRichContentService
        // builds flat_text server-side (every [data-seg] span's text AND
        // every bare boundary text node between them, in the same order),
        // so an annotation's stored offsets land on the right spot
        // regardless of images/tables in between.
        function findDocxRangeAt(container, start, end) {
            const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
            let node; let cursor = 0; let startNode; let startOffset; let endNode; let endOffset;
            while ((node = walker.nextNode())) {
                const length = node.textContent.length;
                // Strictly greater than, not >= — a node whose range ENDS
                // exactly at `start` doesn't CONTAIN that offset, the next
                // node does (its first character). Using >= here was the
                // actual bug: a flagged range starting right where the
                // preceding boundary "\n" ends landed one character early,
                // selecting that "\n" instead of the real text — see
                // DocxRichContentService::collapsePageBreakGaps()'s own
                // docblock for why this exact invariant matters.
                if (startNode === undefined && cursor + length > start) {
                    startNode = node;
                    startOffset = start - cursor;
                }
                if (cursor + length >= end) {
                    endNode = node;
                    endOffset = end - cursor;
                    break;
                }
                cursor += length;
            }
            if (!startNode || !endNode) return null;

            const range = document.createRange();
            range.setStart(startNode, Math.max(0, Math.min(startOffset, startNode.textContent.length)));
            range.setEnd(endNode, Math.max(0, Math.min(endOffset, endNode.textContent.length)));

            return range;
        }

        contentEl.addEventListener('change', function (e) {
            const checkbox = e.target.closest('input[name="resolved_annotation_ids[]"]');
            if (!checkbox) return;

            updateSaveRevisionButtonState();

            if (!checkbox.checked) return;

            const start = Number(checkbox.dataset.start);
            const end = Number(checkbox.dataset.end);
            if (Number.isNaN(start) || Number.isNaN(end)) return;

            const textarea = document.getElementById('revision-text-editor');
            if (textarea) {
                // focus()/setSelectionRange() FIRST, scroll positioning
                // LAST — confirmed live that it has to be this order:
                // calling focus() on a textarea resets its scrollTop back
                // to 0 regardless of what it was set to beforehand, which
                // was silently undoing a scrollTop set before focus().
                textarea.focus();
                textarea.setSelectionRange(start, end);

                // Two DIFFERENT scroll containers, both needed — same
                // page-level visibility the .docx editor already gets via
                // its own scrollIntoView() call just below. Confirmed live
                // on a real document: the textarea's OWN internal scroll
                // was already landing correctly (0 -> 204), but the
                // textarea element itself sat ~750px down the page with
                // nothing ever scrolling the PAGE to bring it into view —
                // so the fix was invisible even though it technically
                // worked. scrollIntoView() here handles the page; the
                // scrollTop line below still handles the line position
                // WITHIN the now-visible textarea.
                textarea.scrollIntoView({ block: 'center', behavior: 'smooth' });

                // Approximate scroll position — counts newlines before the
                // flagged passage to estimate a line number. This textarea
                // wraps long lines rather than using white-space: pre, so a
                // very long unbroken line can throw the estimate off by a
                // little; setSelectionRange above is what actually has to
                // be exact (a native character-offset selection, unaffected
                // by wrapping), this is only getting it roughly into view.
                const linesBefore = textarea.value.slice(0, start).split('\n').length - 1;
                const lineHeight = parseFloat(getComputedStyle(textarea).lineHeight) || 20;
                textarea.scrollTop = Math.max(0, lineHeight * linesBefore - textarea.clientHeight / 2);

                return;
            }

            const editor = document.getElementById('docx-revision-editor');
            if (!editor) return;

            const range = findDocxRangeAt(editor, start, end);
            if (!range) return;

            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            range.startContainer.parentElement?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });

        // A .docx's "Document text" area (see tracking-content.blade.php)
        // is a real contenteditable rendering of the document, not a
        // <textarea> — a native form submit has no field value to send
        // for it on its own. Right before the browser submits, read every
        // [data-seg] element's own current text back out, in document
        // order (DocxRichContentService::render()'s $tagSegments gives
        // each one that same order server-side — see applyTextEdits()),
        // and inject it as a hidden field the server actually receives.
        // Not preventDefault()'d — this only augments the form that's
        // about to submit normally anyway.
        //
        // Sent as ONE JSON-encoded field, not one segment[]=... field per
        // segment — Laravel's default TrimStrings middleware trims every
        // individual string input, which silently ate a segment's own
        // meaningful trailing space ("Label: " -> "Label:") when each
        // segment was its own field. A single JSON string's INNER
        // whitespace is untouched either way — only its own outer edges
        // (never meaningful here) are trimmed — so decoding it server-
        // side sidesteps the whole problem without touching global
        // middleware config.
        contentEl.addEventListener('submit', function (e) {
            const form = e.target.closest('#docx-revision-form');
            const editor = document.getElementById('docx-revision-editor');
            if (!form || !editor) return;

            form.querySelectorAll('input[name="segment_texts_json"]').forEach((el) => el.remove());

            const texts = Array.from(editor.querySelectorAll('[data-seg]')).map((span) => span.textContent);

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'segment_texts_json';
            hidden.value = JSON.stringify(texts);
            form.appendChild(hidden);
        });
    });
</script>
@endsection
