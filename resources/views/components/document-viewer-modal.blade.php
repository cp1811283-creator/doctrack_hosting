{{--
    Embedded original-file viewer (Feature: view the exact uploaded file
    in-system rather than downloading it). One shared modal, invoked from
    anywhere via JS: openDocumentViewer(url, mimeType, filename, documentId).
    documentId is optional — omitted at call sites that aren't really
    "reviewing" a document (e.g. the ML training panels), which simply
    means no presence icon fetch happens there.

    Rendering strategy per mime type:
      - .docx (wordprocessingml.document) -> fetched as server-rendered HTML (DocxRichContentService, same as Review &
        Comment), built from the real file (kept surgically in sync with any approved revision — see WorkflowService::
        saveDocxRevision()).
      - everything else (application/pdf, image/*, legacy .doc, text/plain) -> fetched & shown as preformatted text —
        this same URL now serves the extracted (and possibly revised) TEXT for all of these, not the original file's
        raw bytes, since text is the only thing ever revised for them — see DocumentController::viewFile()'s own
        comment for why.

    Presence icon: while open, polls documents.presence every ~8s (see
    __pollPresence below) for who else is currently reviewing this exact
    document — approvers only, per DocumentReviewSession. Each poll also
    heartbeats the current viewer's own session server-side. Closing the
    modal (or unloading the page) also fires an explicit presence-leave
    beacon (__sendPresenceLeave), which closes the session for real
    server-side (see DocumentController::presenceLeave()) — the icon
    disappears for other viewers instantly rather than waiting out the
    heartbeat decay, and the time spent on this review pass is correctly
    recorded instead of lost.
--}}
{{-- Flat tint, NOT backdrop-blur — see layouts/app.blade.php's
     #connection-status comment for why: blur measurably lags on weaker
     graphics hardware, a flat semi-transparent tint doesn't. --}}
<div id="doc-viewer-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/70 flex items-center justify-center p-4"
     data-presence-url-template="{{ route('documents.presence', ['document' => '__ID__']) }}"
     data-presence-leave-url-template="{{ route('documents.presence.leave', ['document' => '__ID__']) }}"
     onclick="if(event.target === this) closeDocumentViewer()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 flex-shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <h3 id="doc-viewer-title" class="text-sm font-semibold text-surface-900 truncate pr-2">Document Preview</h3>
                <details id="doc-viewer-presence" class="relative hidden flex-shrink-0">
                    <summary class="list-none cursor-pointer flex items-center -space-x-1.5">
                        <span id="doc-viewer-presence-avatars" class="flex items-center -space-x-1.5"></span>
                        <span class="ml-2 text-[11px] text-approved-700 font-medium flex items-center gap-1">
                            <span class="relative flex w-1.5 h-1.5">
                                <span class="absolute inline-flex h-full w-full rounded-full bg-approved-400 opacity-75 animate-ping"></span>
                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-approved-500"></span>
                            </span>
                            <span id="doc-viewer-presence-count"></span> reviewing now
                        </span>
                    </summary>
                    <div id="doc-viewer-presence-names" class="absolute z-10 mt-1 left-0 bg-white rounded-lg shadow-lg border border-surface-200 py-1.5 min-w-[200px]"></div>
                </details>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <a id="doc-viewer-newtab" href="#" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline font-medium">Open in new tab</a>
                <button type="button" onclick="__printViewer()" class="text-surface-400 hover:text-surface-700" aria-label="Print document" title="Print">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.318 2.647a.75.75 0 01-.74.853H6.762a.75.75 0 01-.74-.853L6.34 18m11.32 0H6.34m10.94-9.75V4.243a.75.75 0 00-.75-.75H7.47a.75.75 0 00-.75.75V8.25m10.94 0H6.72"/>
                    </svg>
                </button>
                <button type="button" onclick="closeDocumentViewer()" class="text-surface-400 hover:text-surface-700" aria-label="Close preview">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
        <div id="doc-viewer-body" class="flex-1 overflow-auto bg-surface-50 relative"></div>
    </div>
</div>

{{-- Printing copies the document into a clean print area appended to the
     end of the page (see __printClone below), and hides everything else
     with display:none — which, unlike visibility:hidden, removes the space
     it occupied. Otherwise the hidden app layout left a blank first page
     and pushed the document's content down. --}}
<style>
    @media print {
        @page { margin: 12mm; }
        body.printing-clone > *:not(#doc-print-root) { display: none !important; }
        #doc-print-root { display: block !important; position: static !important; height: auto !important; max-height: none !important; overflow: visible !important; box-shadow: none !important; }
        #doc-print-root * { box-shadow: none !important; }
        #doc-print-root img, #doc-print-root table, #doc-print-root tr { break-inside: avoid; page-break-inside: avoid; }
        #doc-print-root, #doc-print-root > div { padding: 0 !important; margin: 0 !important; max-width: none !important; border-radius: 0 !important; }
        #doc-print-root .docx-page-divider { display: none !important; }
        #doc-print-root .docx-page-gap { break-after: page; page-break-after: always; }
        #doc-print-root .docx-figure { display: block; break-inside: avoid; page-break-inside: avoid; }
        #doc-print-root img { max-height: 230mm !important; max-width: 100% !important; }
    }
</style>

<script>
    let __presencePollTimer = null;
    let __presenceDocumentId = null;

    function __escapeHtmlAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function __sendPresenceLeave() {
        if (!__presenceDocumentId) return;
        const overlay = document.getElementById('doc-viewer-overlay');
        const url = overlay.dataset.presenceLeaveUrlTemplate.replace('__ID__', __presenceDocumentId);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(url, {
            method: 'POST',
            keepalive: true,
            headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        }).catch(() => {});
        __presenceDocumentId = null;
    }

    function __stopPresencePoll() {
        if (__presencePollTimer) {
            clearInterval(__presencePollTimer);
            __presencePollTimer = null;
        }
        __sendPresenceLeave();
        const el = document.getElementById('doc-viewer-presence');
        el.classList.add('hidden');
        el.removeAttribute('open');
    }

    // Covers closing the browser tab/navigating away without ever
    // clicking the X — closeDocumentViewer() alone can't catch that.
    window.addEventListener('pagehide', __sendPresenceLeave);

    function __renderPresence(viewers) {
        const el = document.getElementById('doc-viewer-presence');
        if (!viewers || viewers.length === 0) {
            el.classList.add('hidden');
            el.removeAttribute('open');
            return;
        }
        el.classList.remove('hidden');

        document.getElementById('doc-viewer-presence-count').textContent = viewers.length;

        const avatars = viewers.slice(0, 4).map((v) => {
            const initial = (v.name || '?').charAt(0).toUpperCase();
            return `<span class="w-5 h-5 rounded-full bg-approved-500 text-white text-[9px] font-bold flex items-center justify-center ring-2 ring-white shadow-sm" title="${__escapeHtmlAttr(v.name)} is currently reviewing">${__escapeHtmlAttr(initial)}</span>`;
        }).join('');
        document.getElementById('doc-viewer-presence-avatars').innerHTML = avatars;

        const names = viewers.map((v) => `<div class="px-3 py-1.5 text-xs">
            <p class="font-medium text-surface-800">${__escapeHtmlAttr(v.name)}</p>
            <p class="text-[10px] text-surface-400">${__escapeHtmlAttr(v.role)}${v.category ? ' &middot; ' + __escapeHtmlAttr(v.category) : ''}</p>
        </div>`).join('');
        document.getElementById('doc-viewer-presence-names').innerHTML = names;
    }

    function __pollPresence(url) {
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => __renderPresence(data.viewers))
            .catch(() => {});
    }

    function __startPresencePoll(documentId) {
        __stopPresencePoll();
        if (!documentId) return;
        __presenceDocumentId = documentId;

        const overlay = document.getElementById('doc-viewer-overlay');
        const url = overlay.dataset.presenceUrlTemplate.replace('__ID__', documentId);

        __pollPresence(url);
        __presencePollTimer = setInterval(() => __pollPresence(url), 8000);
    }

    // Marks the page as "printing the viewer" for exactly this print, so
    // the Review & Comment popup underneath (which has its own print
    // rule) is hidden from the print instead of duplicating its copy of
    // the document. Cleared again the moment the print dialog closes.
    // Copies $sourceEl's content into a clean print area appended to the
    // page, prints just that, then removes it again. Used by this viewer
    // AND the Review & Comment popup (see annotations-panel.blade.php) —
    // both are fixed-height scrolling boxes, which is why printing them
    // in place only ever produced the first screen's worth of content.
    function __printClone(sourceEl) {
        if (!sourceEl) return;
        const root = document.createElement('div');
        root.id = 'doc-print-root';
        root.className = sourceEl.className;
        root.setAttribute('style', sourceEl.getAttribute('style') || '');
        root.innerHTML = sourceEl.innerHTML;
        document.body.appendChild(root);
        document.body.classList.add('printing-clone');

        const cleanup = () => {
            root.remove();
            document.body.classList.remove('printing-clone');
        };
        window.addEventListener('afterprint', cleanup, { once: true });
        window.print();
        cleanup();
    }

    function __printViewer() {
        __printClone(document.getElementById('doc-viewer-body'));
    }

    function __escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function __showFallback(body, url, message) {
        body.innerHTML = `<div class="absolute inset-0 flex flex-col items-center justify-center text-center p-6 gap-2">
            <p class="text-sm text-surface-500">${message}</p>
            <a href="${url}" target="_blank" rel="noopener" class="text-primary-700 hover:underline text-sm font-medium">Open in new tab instead &rarr;</a>
        </div>`;
    }

    function closeDocumentViewer() {
        // Captured BEFORE __stopPresencePoll() — that call sends the
        // presence-leave beacon, which is what closes the real review
        // session server-side and is also what nulls this out.
        const documentId = __presenceDocumentId;

        document.getElementById('doc-viewer-overlay').classList.add('hidden');
        document.getElementById('doc-viewer-body').innerHTML = '';
        __stopPresencePoll();

        // Lets the Approver Queue's minimum-review-time countdown (see
        // startReviewCountdown() in approver/dashboard.blade.php) know the
        // real review session just ended, so it can stop ticking down
        // instead of continuing to count wall-clock seconds as if
        // reviewing were still happening — see that listener's own
        // docblock for why the countdown used to reach 0 and "unlock"
        // Approve/Reject even after someone closed the viewer early.
        if (documentId) {
            window.dispatchEvent(new CustomEvent('documentviewer:closed', { detail: { documentId } }));
        }
    }

    async function openDocumentViewer(url, mimeType, filename, documentId, autoPrint = false) {
        const overlay = document.getElementById('doc-viewer-overlay');
        const title = document.getElementById('doc-viewer-title');
        const newTabLink = document.getElementById('doc-viewer-newtab');
        const body = document.getElementById('doc-viewer-body');

        // Cache-bust every open — some browsers keep an in-memory decoded
        // image cache for <img> (and similarly for iframe/fetch) that's
        // separate from the HTTP cache and can reuse the exact same URL
        // within one page session WITHOUT ever hitting the network, even
        // with Cache-Control: no-store set server-side. That silently
        // skipped this whole viewer's server round-trip on a second open
        // of the same document — no request meant no new review session,
        // no realtime push, and no time recorded for that visit. A
        // genuinely different URL each time can't be served from any
        // cache, HTTP or in-memory, since it's a different resource as
        // far as the browser is concerned.
        const freshUrl = url + (url.includes('?') ? '&' : '?') + '_=' + Date.now();

        title.textContent = filename || 'Document Preview';
        newTabLink.href = freshUrl;
        overlay.classList.remove('hidden');
        body.innerHTML = '<div class="absolute inset-0 flex items-center justify-center text-sm text-surface-400">Loading preview…</div>';
        __startPresencePoll(documentId);

        // Lets any page (e.g. the Approver Queue's minimum-review-time
        // countdown) react to "this document's viewer just opened" without
        // this component needing to know anything about who's listening.
        if (documentId) {
            window.dispatchEvent(new CustomEvent('documentviewer:opened', { detail: { documentId } }));
        }

        mimeType = mimeType || '';

        // Only a real .docx is kept surgically in sync with any approved
        // revision at its ORIGINAL file bytes (see WorkflowService::
        // saveDocxRevision()) — every other type (.pdf, images, legacy
        // .doc, .txt) only ever has its extracted TEXT revised, and this
        // same URL now serves exactly that (see DocumentController::
        // viewFile()'s matching comment) for anything that isn't .docx.
        // So every one of those mime types renders identically here: the
        // current (possibly revised) plain text, not the original file's
        // own raw bytes — only .docx still needs its own real-file path,
        // rendered server-side.
        const isDocx = mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        try {
            if (isDocx) {
                // Same server-side renderer Review & Comment uses (images,
                // tables, bold/italic, collapsed blank-paragraph gaps), so
                // the two views match. Fetched via the same authorized
                // endpoint, so review-session tracking still runs.
                const htmlUrl = freshUrl + (freshUrl.includes('?') ? '&' : '?') + 'format=html';
                const res = await fetch(htmlUrl);
                if (!res.ok) throw new Error('Fetch failed');
                const html = await res.text();
                body.innerHTML = `<div class="bg-white mx-auto my-4 p-8 rounded-lg shadow-card text-base text-surface-800 leading-relaxed" style="max-width: 800px; white-space: pre-wrap;">${html}</div>`;
                if (autoPrint) __printViewer();
            } else {
                const res = await fetch(freshUrl);
                if (!res.ok) throw new Error('Fetch failed');
                const text = await res.text();
                // Same centered "page" card treatment as the .docx branch
                // above — this used to be a bare, left-aligned, full-width
                // block, visibly inconsistent with how a .docx renders.
                body.innerHTML = `<pre class="bg-white mx-auto my-4 p-8 rounded-lg shadow-card text-sm text-surface-700 whitespace-pre-wrap font-mono" style="max-width: 800px;">${__escapeHtml(text)}</pre>`;
                if (autoPrint) __printViewer(); // content above is already synchronously in the DOM
            }
        } catch (e) {
            __showFallback(body, freshUrl, "Couldn't load the preview.");
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDocumentViewer();
    });
</script>