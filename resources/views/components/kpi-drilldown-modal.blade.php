{{--
    Drill-down modal for the Admin dashboard's clickable KPI cards (Feature:
    click a card, see exactly which documents/users it counted). One shared
    modal, invoked from anywhere via JS: openKpiDrilldown(type, label, url)
    — mirrors the fetch-and-inject pattern already used by
    document-viewer-modal.blade.php.
--}}
{{-- Flat tint, NOT backdrop-blur — see layouts/app.blade.php's
     #connection-status comment for why: blur measurably lags on weaker
     graphics hardware, a flat semi-transparent tint doesn't. --}}
<div id="kpi-drilldown-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/70 flex items-center justify-center p-4" onclick="if(event.target === this) closeKpiDrilldown()">
    {{-- Base size here is the WIDE one (every drilldown except 'annotations'
         — KPI lists, Document Tracker, Revision History's own before/after
         comparison, etc. all genuinely need the room) — openKpiDrilldown()
         below swaps in the narrower classes specifically for 'annotations'
         (Review & Comment), and swaps back to this default the moment
         'revision-history' loads into the SAME open modal (its own clock-
         icon button re-calls openKpiDrilldown() without closing first — see
         that function's docblock). h-[85vh] isn't replaced by a max-h here on
         purpose: letting every OTHER drilldown's height still track this one
         exactly as before avoids touching behavior nothing asked to change;
         only 'annotations' gets content-sized height. --}}
    <div id="kpi-drilldown-panel" class="bg-white rounded-xl shadow-2xl w-[90vw] max-w-7xl h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 flex-shrink-0">
            <h3 id="kpi-drilldown-title" class="text-sm font-semibold text-surface-900"></h3>
            <button type="button" onclick="closeKpiDrilldown()" class="text-surface-400 hover:text-surface-700" aria-label="Close">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="kpi-drilldown-body" class="flex-1 overflow-auto"></div>
    </div>
</div>

<script>
    function closeKpiDrilldown() {
        document.getElementById('kpi-drilldown-overlay').classList.add('hidden');
        document.getElementById('kpi-drilldown-body').innerHTML = '';

        // This modal is shared by every KPI drilldown in the app (Admin
        // dashboard cards, SLA popups, etc.), but one of its uses —
        // "Review & Comment" on the Approver Queue — also needs to stop
        // its own presence poll on close (see approver/dashboard.blade.php's
        // openReviewAndComment()). Guarded since that function only exists
        // on pages that actually load approver/dashboard.blade.php's script,
        // and is itself a no-op unless that popup was the one open.
        if (typeof __annotationStopPresencePoll === 'function') {
            // Captured BEFORE __annotationStopPresencePoll() — that call
            // sends the presence-leave beacon (closes the real review
            // session server-side) and is also what nulls this out.
            const documentId = typeof __annotationPresenceDocumentId !== 'undefined' ? __annotationPresenceDocumentId : null;
            __annotationStopPresencePoll();
            // Same "the real session just ended, stop the countdown from
            // ticking in the background" signal document-viewer-modal.
            // blade.php's closeDocumentViewer() sends — see its comment.
            if (documentId) {
                window.dispatchEvent(new CustomEvent('documentviewer:closed', { detail: { documentId } }));
            }
        }
    }

    // The two size presets openKpiDrilldown() toggles between — see the
    // panel's own docblock comment above for why 'annotations' is the one
    // exception. Kept as whole class STRINGS (not a single class each) so
    // add/remove can never leave a stale class from the other preset mixed
    // in with the new one.
    const KPI_DRILLDOWN_SIZE_WIDE = ['w-[90vw]', 'max-w-7xl', 'h-[85vh]'];
    const KPI_DRILLDOWN_SIZE_NARROW = ['w-full', 'max-w-2xl', 'max-h-[85vh]'];

    async function openKpiDrilldown(type, label, url) {
        const overlay = document.getElementById('kpi-drilldown-overlay');
        const panel = document.getElementById('kpi-drilldown-panel');
        const title = document.getElementById('kpi-drilldown-title');
        const body = document.getElementById('kpi-drilldown-body');

        const isNarrow = type === 'annotations';
        panel.classList.remove(...KPI_DRILLDOWN_SIZE_WIDE, ...KPI_DRILLDOWN_SIZE_NARROW);
        panel.classList.add(...(isNarrow ? KPI_DRILLDOWN_SIZE_NARROW : KPI_DRILLDOWN_SIZE_WIDE));

        title.textContent = label;
        overlay.classList.remove('hidden');
        body.innerHTML = '<div class="p-10 text-center text-sm text-surface-400">Loading…</div>';

        try {
            const res = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!res.ok) throw new Error('Fetch failed');
            body.innerHTML = await res.text();
            // Content set via innerHTML never runs its own <script> tags
            // (that's the browser's own behavior, not something worth
            // fighting) — any drilldown whose markup needs real JS wires
            // it up here instead, guarded so this is a no-op for every
            // other drilldown type that's plain static content.
            if (type === 'manage-stages') initManageStagesForm(body);
        } catch (e) {
            body.innerHTML = '<div class="p-10 text-center text-sm text-rejected-600">Couldn\'t load this list.</div>';
        }
    }

    /**
     * Feature: the "Manage Stages" popup (admin/partials/manage-stages-form.blade.php)
     * — switching Category or Department shows only the matching
     * stage-group + department-owned options, unchecking anything hidden
     * (it wouldn't be submitted anyway since it's hidden, but unchecking
     * keeps the UI honest if the admin flips back and forth before
     * submitting). Was a plain inline <script> when this form lived on
     * its own page; scoped to $body (not the whole document) now that
     * more than one drilldown type can exist, and called explicitly from
     * openKpiDrilldown() above rather than relying on a <script> tag that
     * innerHTML would just never execute.
     */
    function initManageStagesForm(body) {
        const categorySelect = body.querySelector('#edit-category');
        const departmentSelect = body.querySelector('#edit-department');
        const levelSelect = body.querySelector('#edit-level');
        const categoryField = body.querySelector('#edit-category-field');
        const stagesField = body.querySelector('#edit-stages-field');
        const headNote = body.querySelector('#edit-head-note');
        if (!categorySelect || !departmentSelect || !levelSelect) return;

        const stageGroups = body.querySelectorAll('.stage-group');

        const refresh = () => {
            const isHead = levelSelect.value === 'head';
            // Category and Specific Stages don't apply to a head — see
            // WorkflowService::eligibleApproversForStage()'s docblock, same
            // reasoning as the Create Account form's toggleApproverFields().
            categoryField.style.display = isHead ? 'none' : 'block';
            stagesField.style.display = isHead ? 'none' : 'block';
            headNote.classList.toggle('hidden', !isHead);

            stageGroups.forEach((group) => {
                const categoryMatches = group.dataset.category === categorySelect.value;
                group.classList.toggle('hidden', !categoryMatches);

                group.querySelectorAll('.stage-option').forEach((option) => {
                    const owners = option.dataset.departments ? option.dataset.departments.split(',') : [];
                    const departmentMatches = owners.length === 0 || owners.includes(departmentSelect.value);
                    const visible = categoryMatches && departmentMatches;
                    option.classList.toggle('hidden', !visible);
                    if (!visible) {
                        option.querySelector('input[type=checkbox]').checked = false;
                    }
                });
            });
        };

        categorySelect.addEventListener('change', refresh);
        departmentSelect.addEventListener('change', refresh);
        levelSelect.addEventListener('change', refresh);
        refresh();
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeKpiDrilldown();
    });

    // Revision History (Feature: Google-Docs-style before/after popup —
    // see documents/partials/revision-history-list.blade.php and
    // revision-diff.blade.php). Delegated on #kpi-drilldown-body, a
    // stable ancestor that survives every fragment swap, same reasoning
    // as the "Withdraw"/Request Revision delegation in approver/
    // dashboard.blade.php. Guarded by data attributes that only exist on
    // this drilldown's own markup, so it's a no-op for every other
    // drilldown type sharing this same modal.
    document.getElementById('kpi-drilldown-body')?.addEventListener('click', async function (e) {
        const body = document.getElementById('kpi-drilldown-body');

        const diffBtn = e.target.closest('[data-revision-diff-url]');
        if (diffBtn) {
            body.innerHTML = '<div class="p-10 text-center text-sm text-surface-400">Loading…</div>';
            try {
                const res = await fetch(diffBtn.dataset.revisionDiffUrl, { headers: { Accept: 'text/html' } });
                if (!res.ok) throw new Error('Fetch failed');
                body.innerHTML = await res.text();
            } catch (err) {
                body.innerHTML = '<div class="p-10 text-center text-sm text-rejected-600">Couldn\'t load this revision.</div>';
            }
            return;
        }

        const backBtn = e.target.closest('[data-back-to-history]');
        if (backBtn) {
            const root = e.target.closest('[data-diff-root]');
            if (!root) return;
            body.innerHTML = '<div class="p-10 text-center text-sm text-surface-400">Loading…</div>';
            try {
                const res = await fetch(root.dataset.listUrl, { headers: { Accept: 'text/html' } });
                if (!res.ok) throw new Error('Fetch failed');
                body.innerHTML = await res.text();
            } catch (err) {
                body.innerHTML = '<div class="p-10 text-center text-sm text-rejected-600">Couldn\'t load history.</div>';
            }
        }
    });
</script>
