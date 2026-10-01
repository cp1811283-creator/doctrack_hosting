import './echo';

// Eye-icon toggle for any password field — delegated on document rather
// than bound per-input, so it works on every page that includes app.js
// (login, password reset, Admin's Create Account form) with zero
// per-page wiring, and keeps working even if that markup is ever swapped
// in via a live-refresh fragment. Markup contract: a button with
// data-toggle-password="<input id>", containing two SVGs marked
// data-eye-open / data-eye-closed (one hidden at a time).
document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-toggle-password]');
    if (!btn) return;

    const input = document.getElementById(btn.dataset.togglePassword);
    if (!input) return;

    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.querySelector('[data-eye-open]')?.classList.toggle('hidden', isHidden);
    btn.querySelector('[data-eye-closed]')?.classList.toggle('hidden', !isHidden);
});

// Live password-strength checklist (x-password-requirements) — delegated
// init on DOMContentLoaded rather than per-page JS, matching the
// data-toggle-password pattern above. Markup contract: a <ul
// data-password-requirements-for="<input id>"> containing <li
// data-rule="length|uppercase|lowercase|number|uncompromised"> items,
// each with data-icon-unmet/-met/(-bad for the last one) SVGs.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-requirements-for]').forEach((list) => {
        const input = document.getElementById(list.dataset.passwordRequirementsFor);
        if (!input) return;

        const staticRules = {
            length: (v) => v.length >= 8,
            uppercase: (v) => /[A-Z]/.test(v),
            lowercase: (v) => /[a-z]/.test(v),
            number: (v) => /[0-9]/.test(v),
        };

        function setState(li, state) {
            li.querySelector('[data-icon-unmet]')?.classList.toggle('hidden', state !== 'idle');
            li.querySelector('[data-icon-met]')?.classList.toggle('hidden', state !== 'met');
            li.querySelector('[data-icon-bad]')?.classList.toggle('hidden', state !== 'bad');
            li.classList.remove('text-surface-400', 'text-approved-700', 'text-rejected-700');
            li.classList.add(state === 'met' ? 'text-approved-700' : state === 'bad' ? 'text-rejected-700' : 'text-surface-400');
        }

        function updateStaticRules() {
            Object.keys(staticRules).forEach((rule) => {
                const li = list.querySelector(`[data-rule="${rule}"]`);
                if (li) setState(li, staticRules[rule](input.value) ? 'met' : 'idle');
            });
        }

        // The one rule that can't be judged locally — "has this exact
        // password shown up in a known data breach." Asked of the server
        // (PasswordBreachController), which queries the same free, keyless
        // Pwned Passwords API Laravel's uncompromised() rule calls at
        // submit. Done server-side rather than in the browser because
        // crypto.subtle only exists on HTTPS/localhost — on a plain-HTTP
        // LAN address the old in-browser check silently did nothing.
        // Four honest states: checking, clean, leaked, and "couldn't
        // check right now" — the last is not a failure of the password;
        // the submit-time check still runs regardless.
        const breachLi = list.querySelector('[data-rule="uncompromised"]');
        const breachText = breachLi?.querySelector('[data-status-text]');
        const breachUrl = list.dataset.breachCheckUrl;
        let breachTimer = null;
        let breachRequestId = 0;

        function setBreachState(state, text) {
            setState(breachLi, state);
            if (breachText) breachText.textContent = text;
        }

        async function checkBreach(value) {
            if (!breachLi || !breachUrl) return;
            const thisRequestId = ++breachRequestId;

            if (value.length < 8) {
                setBreachState('idle', 'Not a known leaked password');
                return;
            }

            setBreachState('idle', 'Checking against known breaches…');

            try {
                const res = await fetch(breachUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': list.dataset.csrf ?? '',
                    },
                    body: JSON.stringify({ password: value }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const { status } = await res.json();
                if (thisRequestId !== breachRequestId) return; // superseded by a later keystroke

                if (status === 'leaked') {
                    setBreachState('bad', 'This password has appeared in a data breach — choose another');
                } else if (status === 'clean') {
                    setBreachState('met', 'Not a known leaked password');
                } else {
                    setBreachState('idle', "Couldn't check right now — it will be checked when you submit");
                }
            } catch {
                if (thisRequestId !== breachRequestId) return;
                setBreachState('idle', "Couldn't check right now — it will be checked when you submit");
            }
        }

        input.addEventListener('input', () => {
            updateStaticRules();
            clearTimeout(breachTimer);
            breachTimer = setTimeout(() => checkBreach(input.value), 600);
        });
    });
});

// --- Connection & responsiveness banner (#connection-status — see
// layouts/app.blade.php) — module-scoped, not nested in one closure,
// so __refreshAjaxPaginationContainer() further down this file can hook
// into it directly. Two independent signals share this one banner, since
// they're both "the system might feel broken right now" from the user's
// point of view, just for different reasons:
//   1. Reverb's WebSocket isn't connected — the background live-update
//      channel is down (see the state_change binding below).
//   2. A user-initiated action (clicking a module link, submitting a
//      form, a pagination click) is taking noticeably long to respond —
//      tracked via trackSlowOp() below. This catches "the page loaded
//      fine and the background connection is fine, but THIS specific
//      click is just hanging" on a weak connection, which a WebSocket-
//      only check can't see at all (a plain page navigation never
//      touches Reverb).
// The banner shows if EITHER signal is bad, and only hides once BOTH are
// clear — __renderConnectionBanner() is the one place that decides
// visibility, so the two signals can never fight over the same toggle.
let __connectionBanner = null;
let __echoConnected = true;
let __activeSlowOps = 0;

function __renderConnectionBanner() {
    if (!__connectionBanner) return;
    __connectionBanner.classList.toggle('hidden', __echoConnected && __activeSlowOps === 0);
}

const SLOW_OP_THRESHOLD_MS = 800;

/**
 * Starts a slow-operation timer; call the returned function once that
 * operation actually finishes. Only counted — and only shows the banner
 * — if it's still running past SLOW_OP_THRESHOLD_MS, so a normal fast
 * click or fetch never triggers anything.
 */
function trackSlowOp() {
    let counted = false;
    const timer = setTimeout(() => {
        counted = true;
        __activeSlowOps += 1;
        __renderConnectionBanner();
    }, SLOW_OP_THRESHOLD_MS);

    return function stop() {
        clearTimeout(timer);
        if (counted) {
            __activeSlowOps = Math.max(0, __activeSlowOps - 1);
            __renderConnectionBanner();
        }
    };
}

document.addEventListener('DOMContentLoaded', () => {
    __connectionBanner = document.getElementById('connection-status');
    if (!__connectionBanner) return;

    const connection = window.Echo?.connector?.pusher?.connection;
    if (connection) {
        connection.bind('state_change', (states) => {
            __echoConnected = states.current === 'connected';
            __renderConnectionBanner();
        });
    }

    // Same-origin navigation (link clicks and form submits) — only one
    // navigation is ever really "in flight" from the user's perspective,
    // so a single shared stop() reference is enough; beforeunload firing
    // means the browser is genuinely leaving, so whatever was pending
    // gets stopped either way.
    let stopNavOp = null;

    function isSameOrigin(rawUrl) {
        try {
            return new URL(rawUrl, window.location.href).origin === window.location.origin;
        } catch {
            return false;
        }
    }

    // A same-page anchor (e.g. "#ml-classification-card" — see the ML
    // Training jump-nav) never fires beforeunload, since the browser
    // doesn't actually navigate anywhere — just scrolls. Without this
    // check, trackSlowOp() below would start a timer nothing ever stops,
    // permanently stuck showing "Reconnecting" until a real page
    // navigation happens to fire beforeunload and clear it. Mirrors the
    // identical same-page-anchor guard the fade-transition click handler
    // just below already has, for the same reason.
    function isSamePageAnchor(link) {
        try {
            const url = new URL(link.href, window.location.href);
            return url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '';
        } catch {
            return false;
        }
    }

    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const link = e.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || !isSameOrigin(link.href) || isSamePageAnchor(link)) return;

        stopNavOp?.();
        stopNavOp = trackSlowOp();
    });

    document.addEventListener('submit', (e) => {
        // Unlike the click listener above, this used to skip the
        // e.defaultPrevented check — so any AJAX-intercepted form
        // (e.preventDefault() + fetch(), no real navigation) still
        // started a nav-tracking timer that only beforeunload ever
        // stops. Since those forms never navigate, beforeunload never
        // fires, and the "Reconnecting" banner got stuck on until the
        // user genuinely left the page (e.g. Approve/Reject and Request
        // Revision on the approver dashboard).
        if (e.defaultPrevented) return;
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.target === '_blank') return;
        if (!isSameOrigin(form.getAttribute('action') || window.location.href)) return;

        stopNavOp?.();
        stopNavOp = trackSlowOp();
    });

    window.addEventListener('beforeunload', () => {
        stopNavOp?.();
        stopNavOp = null;
    });
});

// Browsers that support the View Transitions API also honor the
// @view-transition CSS rule (see app.css) and handle the entire old-page
// -> new-page crossfade natively on every navigation — no JS needed, and
// nothing here should fight it. The manual opacity fade below is only a
// fallback for browsers that don't support it yet, so every place it's
// used is gated on this same check.
const supportsViewTransitions = 'startViewTransition' in document;

// Fade-in on load for a subtle, professional page-transition feel —
// fallback only; browsers with native View Transitions already crossfade
// the incoming page on their own, so doing this too would just layer a
// second, redundant fade on top of it.
if (!supportsViewTransitions) {
    document.addEventListener('DOMContentLoaded', () => {
        document.body.style.opacity = 0;
        requestAnimationFrame(() => {
            document.body.style.transition = 'opacity 150ms ease';
            document.body.style.opacity = 1;
        });
    });

    // Fade the current page out the instant an internal link is clicked,
    // so leaving a page feels like an intentional transition rather than
    // the browser abruptly blanking out mid-navigation. Only for plain,
    // same-origin link clicks — modified clicks (new tab, download,
    // external links, in-page anchors) navigate immediately as normal.
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const link = e.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch {
            return;
        }
        if (url.origin !== window.location.origin) return;
        // Same-page anchor (e.g. "#section") — no navigation happens, so
        // there's nothing to fade for.
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

        e.preventDefault();
        document.body.style.transition = 'opacity 150ms ease';
        document.body.style.opacity = 0;
        setTimeout(() => {
            window.location.href = link.href;
        }, 150);
    });
}

// Notification -> document jump (Feature: a notification click centers the
// document instead of just pinning it to the top — see
// NotificationController::markRead()/ApprovalController::pageForDocument()
// for how the #document-{id} fragment/page number is computed). The
// browser's own default anchor-scroll only supports top-alignment
// (scroll-mt-* controls that), never centering, so this replaces it with
// scrollIntoView({block: 'center'}) instead. That single call is also
// already the right fallback for the first/last document in the queue —
// there's nothing above the first one or below the last one to center
// against, so the browser just scrolls as far as it physically can and
// stops, the same way any scrollIntoView('center') call behaves at either
// end of a scroll container, no special-casing needed here.
//
// Reads window.__pendingScrollHash (stashed by the inline <script> at the
// top of layouts/app.blade.php's <head>), NOT location.hash — by the time
// THIS script runs, the browser may or may not have already consumed the
// hash for its own default (top-aligned) fragment scroll, a race whose
// outcome depends on how fast the page happened to load. The head script
// runs early enough to read and clear the hash before the browser ever
// gets a turn to act on it, removing that race entirely rather than
// trying to win it.
document.addEventListener('DOMContentLoaded', () => {
    const id = window.__pendingScrollHash;
    if (!id) return;

    const target = document.getElementById(id);
    if (!target) return;

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

document.addEventListener('DOMContentLoaded', () => {
    // Approver dashboard SLA countdown ticker.
    const countdownEls = document.querySelectorAll('[data-countdown]');
    if (countdownEls.length) {
        const tick = () => {
            const now = Math.floor(Date.now() / 1000);
            countdownEls.forEach((el) => {
                const target = parseInt(el.dataset.countdown, 10);
                if (!target) return;
                const diff = target - now;
                if (diff <= 0) {
                    el.textContent = 'Overdue';
                    el.classList.add('text-rejected-700');
                    return;
                }
                const h = Math.floor(diff / 3600);
                const m = Math.floor((diff % 3600) / 60);
                el.textContent = `${h}h ${m}m remaining`;
                if (diff < 3600) el.classList.add('text-rejected-700');
            });
        };
        tick();
        setInterval(tick, 30000);
    }

    // Collapsible sidebar (hamburger menu) — a fixed off-canvas drawer,
    // shared by every role's dashboard since they all extend this one
    // layout. Toggled via `transform: translateX()`, not `width` — a
    // width-collapsing flex-sibling version used to live here, but that
    // technique turned out unreliable enough across mobile browsers that
    // the sidebar sometimes wouldn't visibly close at all. Same toggle,
    // same button, identical behavior at every screen width — this is a
    // different, more robust MECHANISM, not a breakpoint-dependent branch.
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarClose = document.getElementById('sidebar-close');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');
    if (sidebar && sidebarToggle) {
        const isOpen = () => sidebar.classList.contains('translate-x-0');

        const openSidebar = () => {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
            sidebarBackdrop?.classList.remove('opacity-0', 'pointer-events-none');
            sidebarBackdrop?.classList.add('opacity-100');
            sidebarToggle.setAttribute('aria-expanded', 'true');
        };
        const closeSidebar = () => {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
            sidebarBackdrop?.classList.add('opacity-0', 'pointer-events-none');
            sidebarBackdrop?.classList.remove('opacity-100');
            sidebarToggle.setAttribute('aria-expanded', 'false');
        };
        const toggleSidebar = () => (isOpen() ? closeSidebar() : openSidebar());

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        sidebarBackdrop?.addEventListener('click', closeSidebar);
    }

    // Click-outside-to-close for transient <details> popovers/menus
    // (notification bell, the deactivation-reason form, etc.) — native
    // <details> only toggles via clicking its own <summary> again, which
    // isn't how a dropdown menu is expected to behave. Deliberately opt-in
    // via a data-popover marker rather than applying to every <details> on
    // the page — plenty of others (violation breach lists, the approver
    // roster, stage edit forms) are "expand to read/edit," not transient
    // menus, and closing those just because you clicked elsewhere on the
    // page would be actively annoying while reading or filling one in.
    document.addEventListener('click', (e) => {
        document.querySelectorAll('details[data-popover][open]').forEach((details) => {
            if (!details.contains(e.target)) {
                details.open = false;
            }
        });
    });

    // Per-device text size control (Feature: readability on small/laptop
    // screens — see components/text-size-control.blade.php and
    // resources/css/app.css's data-text-size rules). Persisted in
    // localStorage, not the user's account — a per-device preference,
    // not something that should follow them to a different, differently-
    // sized screen. The attribute itself is already applied before first
    // paint by the inline script in layouts/app.blade.php's <head>
    // (avoids a flash at the default size); this just wires up the menu
    // and keeps its checkmark in sync.
    const textSizeControl = document.getElementById('text-size-control');
    if (textSizeControl) {
        const applyTextSizeCheck = (size) => {
            textSizeControl.querySelectorAll('[data-text-size-option]').forEach((btn) => {
                btn.querySelector('[data-text-size-check]')?.classList.toggle('hidden', btn.dataset.textSizeOption !== size);
            });
        };

        applyTextSizeCheck(document.documentElement.dataset.textSize || 'normal');

        textSizeControl.querySelectorAll('[data-text-size-option]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const size = btn.dataset.textSizeOption;
                if (size === 'normal') {
                    delete document.documentElement.dataset.textSize;
                } else {
                    document.documentElement.dataset.textSize = size;
                }
                try {
                    localStorage.setItem('textSize', size);
                } catch (e) {}
                applyTextSizeCheck(size);
                textSizeControl.open = false;

                // Every JS-computed height cap in this app (Document
                // Tracker, Your Submissions, User Accounts, Document
                // Tracking, Audit Logs, the Calendar — see
                // sizeCappedCard()'s own docblock) already re-measures on
                // a plain window resize, since a static CSS calc() can't
                // account for the variable space above those cards. A
                // root font-size change never fires that event on its
                // own, so without this, switching text size left every
                // one of those pages with a stale, too-small-or-too-large
                // cap until the next real window resize — this makes the
                // text-size change reuse that exact same, already-wired-
                // everywhere mechanism instead of needing its own.
                window.dispatchEvent(new Event('resize'));
            });
        });
    }

    // Notification bell — live unread count/list, present on every page.
    // Instant via Reverb (see startLiveChannel below); the slow poll is
    // just a safety net in case the WebSocket connection is down. Swaps
    // skip while the dropdown is currently open (the user is actively
    // reading it), preserving whatever they're looking at instead of
    // reshuffling it mid-read.
    const bell = document.getElementById('notification-bell');
    if (bell) {
        const bellOpts = {
            refreshUrl: bell.dataset.refreshUrl,
            target: bell,
            isBusy: () => bell.open,
        };
        startLiveChannel(`user.${bell.dataset.userId}`, '.notification.created', bellOpts);
        startLivePoll({ ...bellOpts, pollUrl: bell.dataset.pollUrl, minDelay: 45, maxDelay: 75 });

        // Opening the bell IS the read receipt (Feature: no more separate
        // "Mark all read" button, which used to hide everything it
        // touched since the dropdown only ever showed unread ones — see
        // refresh() in NotificationController, which now shows recent
        // notifications regardless of read status). Forces the refresh
        // past isBusy's normal "don't reshuffle while open" guard, since
        // this swap is only replacing dot/tint styling on content the
        // user is already looking at, not reordering it.
        bell.addEventListener('toggle', () => {
            if (!bell.open) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            fetch(bell.dataset.markAllReadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
            })
                .then(() => applyLiveRefresh({ ...bellOpts, isBusy: () => false }))
                .catch(() => {});
        });

        // Account deactivation — same already-open per-user channel as the
        // bell above, just a second event on it. Logs the session out
        // server-side (not just a client-side redirect) so it can't be
        // bypassed by navigating back; reuses the existing logout route
        // rather than a bespoke endpoint.
        if (window.Echo) {
            window.Echo.private(`user.${bell.dataset.userId}`).listen('.account.deactivated', () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                fetch('/logout', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                }).finally(() => {
                    window.location.href = '/login';
                });
            });
        }

        // Presence heartbeat (Feature: online-based "Available" status,
        // and the chat widget's online dot) — pings while this tab is
        // open; ages out on its own server-side (see User::isOnline()) if
        // the tab closes or the connection dies, no explicit "I'm
        // leaving" signal needed. Piggybacks on the bell's presence check
        // (bell only renders when authenticated) rather than a separate marker.
        const sendHeartbeat = () => {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            fetch('/presence/heartbeat', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
            }).catch(() => {});
        };
        sendHeartbeat();
        setInterval(sendHeartbeat, 60000);
    }

    initChatWidget();
});

/**
 * Fetches `opts.refreshUrl`, swaps the result into `opts.target`, and runs
 * `opts.onSwap`. Shared by both trigger mechanisms below — a poll cycle
 * that noticed something changed, or an instant WebSocket push — so a
 * live update behaves identically no matter which one triggered it.
 *
 * @param {Object} opts
 * @param {string|Function} opts.refreshUrl - HTML-fragment URL to swap into `target` — a
 *   plain string for a fixed target, or a function returning one (or a falsy
 *   value to skip this refresh) for a target that moves around, like a
 *   shared popup whose content depends on whatever's currently open in it.
 * @param {Element} opts.target - element whose innerHTML gets replaced
 * @param {Function} [opts.isBusy] - return true to skip this update (e.g. user mid-input); caller decides whether to retry
 * @param {Function} [opts.onSwap] - called after a successful swap, e.g. to re-apply a client-side filter
 * @param {boolean} [opts.preserveQueryString] - forward the current page's ?query to refreshUrl (for filtered lists)
 * @param {*} [signalData] - passed through to onSwap (e.g. the poll payload that triggered this)
 */
function applyLiveRefresh(opts, signalData) {
    if (opts.isBusy && opts.isBusy()) return;

    const refreshUrl = typeof opts.refreshUrl === 'function' ? opts.refreshUrl() : opts.refreshUrl;
    if (!refreshUrl) return;

    const url = opts.preserveQueryString ? refreshUrl + window.location.search : refreshUrl;

    fetch(url, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            opts.target.innerHTML = html;
            if (opts.onSwap) opts.onSwap(signalData);
        })
        .catch(() => {});
}
// Exposed for callers outside this module (e.g. an inline page script that
// wants to trigger the exact same fragment-swap after its own action
// succeeds — a form submit, for instance — instead of only reacting to a
// poll/channel signal).
window.applyLiveRefresh = applyLiveRefresh;

/**
 * Real-time, primary update mechanism: subscribes to a private Reverb
 * channel and applies a live refresh the instant the given event fires —
 * no polling delay. Used for everything that can change from another
 * user's action (a new document routed to an approver, a status change,
 * a new notification) so it shows up genuinely live, not on the next
 * check.
 *
 * @param {string} channelName - without the leading "private-" (Echo adds it)
 * @param {string} eventName - e.g. '.document.status-changed' (leading dot = no namespace prefix)
 * @param {Object} opts - same shape as applyLiveRefresh's opts
 * @param {Function} [opts.filter] - (eventData) => bool; skip the refresh entirely
 *   if this returns false. For a channel that carries events for more than
 *   one thing (e.g. an originator's channel covers ALL of their documents),
 *   this is how a single-document page ignores events about other documents.
 */
function startLiveChannel(channelName, eventName, opts) {
    if (!window.Echo) return;
    window.Echo.private(channelName).listen(eventName, (data) => {
        if (opts.filter && !opts.filter(data)) return;
        applyLiveRefresh(opts, data);
    });
}
window.startLiveChannel = startLiveChannel;

/**
 * Fallback safety net, not the primary mechanism — mirrors this app's own
 * SLA architecture (EscalateAssignmentJob fires instantly via the queue,
 * with a slow periodic sweep behind it in case that ever misses). If the
 * WebSocket connection drops, this still eventually catches up instead of
 * silently going stale forever.
 *
 * @param {Object} opts - same shape as applyLiveRefresh's opts, plus:
 * @param {string} opts.pollUrl - returns JSON to compare against the last-seen value
 * @param {number} [opts.minDelay] - seconds, default 45
 * @param {number} [opts.maxDelay] - seconds, default 75
 */
function startLivePoll(opts) {
    let lastSignal = null; // null = "not established yet", first poll just primes it, never swaps
    const minDelay = opts.minDelay ?? 45;
    const maxDelay = opts.maxDelay ?? 75;

    const scheduleNext = () => setTimeout(poll, (minDelay + Math.random() * (maxDelay - minDelay)) * 1000);

    const poll = () => {
        fetch(opts.pollUrl, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                const signal = JSON.stringify(data);
                if (lastSignal === null) {
                    lastSignal = signal; // establish baseline on first successful poll, don't swap
                    return;
                }
                if (signal === lastSignal) return;
                if (opts.isBusy && opts.isBusy()) return; // leave lastSignal stale so we retry next cycle

                applyLiveRefresh(opts, data);
                lastSignal = signal;
            })
            .catch(() => {})
            .finally(scheduleNext);
    };

    scheduleNext();
}
window.startLivePoll = startLivePoll;

/**
 * Caps $cardEl's height to whatever real space is actually left inside
 * <main> below its own top edge, instead of a static CSS
 * h-[calc(100vh-Xrem)] class (used by Your Submissions, User Accounts,
 * Document Tracking, Audit Logs, and the Operational Calendar). That
 * static calc() assumes ONLY the header sits above the card — true on a
 * quiet page load, false the instant a flash message or validation-error
 * banner also renders above it (see layouts/app.blade.php), which pushed
 * the card past the bottom of the screen and forced the whole page
 * (<main> is overflow-y-auto) into an unwanted scroll instead of the
 * card simply being a little shorter that one time. Same live-measurement
 * technique originator/tracking.blade.php's sizeDocumentTracker() already
 * uses successfully for a similarly variable amount of space above it.
 * Called once on load and on resize — nothing above these cards ever
 * changes after that (a flash message is only ever present on the
 * page's own initial load), so a live-channel/poll swap doesn't need to
 * re-measure it.
 *
 * @param {HTMLElement} cardEl - the element to size; must be a flex/flex-col box with its OTHER dimensions (width, position) already set by CSS
 */
function sizeCappedCard(cardEl) {
    const mainEl = document.querySelector('main');
    if (!cardEl || !mainEl) return;

    const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
    const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - cardEl.getBoundingClientRect().top;
    cardEl.style.height = Math.max(available, 200) + 'px';
}
window.sizeCappedCard = sizeCappedCard;

/**
 * Shrinks/grows the folder tiles on a "Browse by Category" grid (Archive,
 * SLA Violation Reports) to exactly fill whatever vertical space is left
 * below the grid's own top edge — no internal scrollbar, no static h-64
 * guess that overflows the page once the grid needs more room than that
 * (more categories, or a larger font size growing whatever sits above it).
 * Same live-measurement technique as sizeCappedCard()/sizeRecentActivity(),
 * applied per-row instead of to one box: height is divided evenly across
 * however many rows the fixed 2-column grid works out to, minus the fixed
 * tab height and the gaps between rows, then applied to every tile body.
 * Deliberately no floor — see sizeRecentActivity() in admin/dashboard.
 * blade.php for why a floor that can exceed real available space is
 * exactly the bug this (and that) exists to avoid.
 *
 * @param {HTMLElement} gridEl - the `.folder-grid` element (a `grid grid-cols-2` box)
 */
function sizeFolderGrid(gridEl) {
    const mainEl = document.querySelector('main');
    if (!gridEl || !mainEl) return;

    const tabs = gridEl.querySelectorAll('.folder-tile-tab');
    const bodies = gridEl.querySelectorAll('.folder-tile-body');
    if (!bodies.length) return;

    const rows = Math.ceil(bodies.length / 2);
    const rowGap = parseFloat(getComputedStyle(gridEl).rowGap) || 0;
    const tabHeight = tabs[0] ? tabs[0].getBoundingClientRect().height : 0;
    const mainPaddingBottom = parseFloat(getComputedStyle(mainEl).paddingBottom) || 0;
    const available = mainEl.getBoundingClientRect().bottom - mainPaddingBottom - gridEl.getBoundingClientRect().top;

    const perRow = (available - (rows - 1) * rowGap) / rows;
    const bodyHeight = Math.max(perRow - tabHeight, 0);
    bodies.forEach((body) => { body.style.height = bodyHeight + 'px'; });
}
window.sizeFolderGrid = sizeFolderGrid;

/**
 * Client-side "fitted" pagination (Feature: a long list is paged by
 * however many rows genuinely fit the device's real screen space, not a
 * fixed guessed count). Two earlier approaches — a single-shot height
 * estimate, then a self-correcting reload loop — both still either
 * clipped a row or left visible leftover whitespace, because a FIXED
 * row-count can't simultaneously be right for a page of uniform short
 * rows and a page containing one unusually tall row (an Approver's
 * stacked badges/buttons on admin/users.blade.php; an extra "Validation
 * issues" sub-row under a failed-validation document on
 * originator/dashboard.blade.php). A later attempt at fixing that kept a
 * server-side batch size and only fixed *that* batch's overflow, which
 * meant the numbered page-jump links had to be dropped (the server can
 * only ever know one batch ahead). Neither compromise was acceptable, so
 * this instead assumes the server has sent the ENTIRE matching list in
 * one response — true for every list this powers today (one originator's
 * own documents; the company's account list) — and does all of the
 * pagination client-side: measure every row once, work out every page's
 * exact real boundary in one pass, then render the same original
 * pagination component (resources/views/vendor/pagination/tailwind.blade.php)
 * by hand, since a real LengthAwarePaginator no longer exists to drive
 * it. Because the whole list already lives in the DOM, every page —
 * reached via Next, Previous, or a direct page-number click — is instant
 * and exactly as full as it can be without overflowing, with zero network
 * requests.
 *
 * A "logical item" can span more than one <tr> (document + its
 * validation-issues row, say) — every <tr> belonging to one item must
 * share the same `data-row-group` value so they're always shown/hidden
 * together.
 *
 * @param {string} listElId - the card/fragment root
 * @param {string} groupSelector - CSS selector matching every row belonging to any logical item
 * @returns {{refit: Function}} refit() recomputes every page boundary from scratch and returns to page 1 —
 *   call after any OTHER code (e.g. a live-channel/poll swap) replaces this fragment's rows out from under this instance.
 */
function initFittedPagination(listElId, groupSelector) {
    let boundaries = []; // [{start, end}, ...] group-index ranges (end exclusive), one entry per page
    let currentPageIndex = 0;

    function groupRows() {
        const listEl = document.getElementById(listElId);
        const rows = listEl ? Array.from(listEl.querySelectorAll(groupSelector)) : [];
        const groups = [];
        rows.forEach((row) => {
            const key = row.dataset.rowGroup;
            let group = groups.find((g) => g.key === key);
            if (!group) {
                group = { key, rows: [] };
                groups.push(group);
            }
            group.rows.push(row);
        });
        return groups;
    }

    // Rows before `start` stay hidden (so they take up no space and the
    // first visible group naturally lands at the real top of the
    // fixed-height area), then walks forward from `start` until the next
    // group's bottom edge would cross the area's own bottom edge.
    function findPageEnd(groups, area, start) {
        groups.forEach((g, i) => g.rows.forEach((r) => r.classList.toggle('hidden', i < start)));
        const areaBottom = area.getBoundingClientRect().bottom;
        let end = groups.length;
        for (let i = start; i < groups.length; i++) {
            const lastRowOfGroup = groups[i].rows[groups[i].rows.length - 1];
            if (lastRowOfGroup.getBoundingClientRect().bottom > areaBottom + 1) {
                end = i;
                break;
            }
        }
        // Always show at least one item — a single unusually tall item
        // shouldn't just vanish with nothing shown at all.
        return Math.max(start + 1, end);
    }

    // One full start-to-finish walk of the list, recording every page's
    // boundary against the area's CURRENT real height.
    function computeBoundariesOnePass(groups, area) {
        const result = [];
        let start = 0;
        while (start < groups.length) {
            const end = findPageEnd(groups, area, start);
            result.push({ start, end });
            start = end;
        }
        return result;
    }

    // Same markup as renderNav()'s <nav> wrapper, just enough content to
    // get its REAL rendered height (prev/next + one page number — the
    // bar's height never depends on how many page buttons it ends up
    // holding, only its width does) without yet knowing the real page
    // count.
    function renderNavSkeleton(navEl) {
        navEl.innerHTML = `
            <nav aria-hidden="true" class="flex items-center justify-center gap-1 rounded-2xl border border-surface-200 bg-white px-3 py-2 text-sm w-fit mx-auto">
                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg">Previous</span>
                <div class="flex items-center gap-1"><span class="w-8 h-8 flex items-center justify-center rounded-lg border-2">1</span></div>
                <div class="w-px h-5 bg-surface-200 mx-1"></div>
                <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg">Next</span>
            </nav>
        `;
    }

    // Walks the whole list to find every page boundary. The pagination bar
    // itself is a sibling of the fitting area and shrinks that area once
    // it has real content (see the card's flex layout in
    // submissions.blade.php / users_table.blade.php) — so a first pass
    // measured with an still-empty nav can overstate how much room a page
    // actually has, clipping its last row the instant the nav bar then
    // renders for real. Once that first pass finds more than one page,
    // reserve the nav's real height (it doesn't vary with exact page
    // count) and measure again before trusting the result — going from no
    // nav to a nav can only ever shrink the area, never grow it, so a
    // second pass is always enough to settle.
    function computeBoundaries() {
        const listEl = document.getElementById(listElId);
        const area = listEl?.querySelector('.js-adaptive-rows-area');
        const navEl = document.getElementById(listElId + '-pagination');
        const groups = groupRows();
        if (!listEl || !area || !navEl || !groups.length) return [];

        navEl.innerHTML = '';
        let result = computeBoundariesOnePass(groups, area);

        if (result.length > 1) {
            renderNavSkeleton(navEl);
            result = computeBoundariesOnePass(groups, area);
        }

        return result;
    }

    function showPage(index) {
        const listEl = document.getElementById(listElId);
        if (!listEl || !boundaries.length) return;

        const groups = groupRows();
        currentPageIndex = Math.max(0, Math.min(index, boundaries.length - 1));
        const { start, end } = boundaries[currentPageIndex];
        groups.forEach((g, i) => g.rows.forEach((r) => {
            const onPage = i >= start && i < end;
            r.classList.toggle('hidden', !onPage);
            // A same-page, client-side search filter (see
            // originator/dashboard.blade.php's applySubmissionFilter() or
            // admin/audit_logs.blade.php's applyDocumentFilter()) toggles
            // this exact 'hidden' class too, over EVERY matching row in
            // the DOM — safe under the old server-paginated design, where
            // only the current page's rows existed in the DOM at all, but
            // every row from every page lives in the DOM now. Without this
            // marker, a search term could reveal a row pagination had
            // deliberately hidden on another page; both of those filter
            // functions check it and skip toggling 'hidden' on a row this
            // marks off-page, leaving pagination's own hide alone.
            r.dataset.fittedOffPage = onPage ? '0' : '1';
        }));
        renderNav();
    }

    // Same markup/classes as resources/views/vendor/pagination/tailwind.blade.php
    // (the app-wide default pagination component) — hand-built here since
    // there's no real LengthAwarePaginator driving it anymore, but it
    // should look and behave identically to every other paginated list in
    // the app, just without a page reload.
    function renderNav() {
        const navEl = document.getElementById(listElId + '-pagination');
        if (!navEl) return;

        if (boundaries.length <= 1) {
            navEl.innerHTML = '';
            return;
        }

        const onFirst = currentPageIndex === 0;
        const onLast = currentPageIndex === boundaries.length - 1;

        let numbersHtml = '';
        boundaries.forEach((_, i) => {
            const page = i + 1;
            numbersHtml += i === currentPageIndex
                ? `<span aria-current="page" class="w-8 h-8 flex items-center justify-center rounded-lg border-2 border-surface-800 font-semibold text-surface-900">${page}</span>`
                : `<button type="button" data-fitted-page="${i}" aria-label="Go to page ${page}" class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-600 hover:bg-surface-50 transition-colors">${page}</button>`;
        });

        const prevHtml = onFirst
            ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-300 cursor-default select-none" aria-disabled="true"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Previous</span>`
            : `<button type="button" data-fitted-prev class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-600 hover:bg-surface-50 transition-colors"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Previous</button>`;
        const nextHtml = onLast
            ? `<span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-300 cursor-default select-none" aria-disabled="true">Next<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></span>`
            : `<button type="button" data-fitted-next class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-600 hover:bg-surface-50 transition-colors">Next<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>`;

        navEl.innerHTML = `
            <nav role="navigation" aria-label="Pagination Navigation"
                class="flex items-center justify-center gap-1 rounded-2xl border border-surface-200 bg-white px-3 py-2 text-sm w-fit mx-auto">
                ${prevHtml}
                <div class="flex items-center gap-1">${numbersHtml}</div>
                <div class="w-px h-5 bg-surface-200 mx-1"></div>
                ${nextHtml}
            </nav>
        `;

        navEl.querySelector('[data-fitted-prev]')?.addEventListener('click', () => showPage(currentPageIndex - 1));
        navEl.querySelector('[data-fitted-next]')?.addEventListener('click', () => showPage(currentPageIndex + 1));
        navEl.querySelectorAll('[data-fitted-page]').forEach((btn) => {
            btn.addEventListener('click', () => showPage(parseInt(btn.dataset.fittedPage, 10)));
        });
    }

    function refit() {
        boundaries = computeBoundaries();
        showPage(0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refit);
    } else {
        refit();
    }

    return {
        refit,
        // Feature: restoring the page you were on after navigating away
        // and back (see admin/audit_logs.blade.php) — 1-indexed to match
        // what's shown on the page-number buttons themselves. A no-op if
        // boundaries haven't been computed yet or the page number is out
        // of range (showPage() already clamps it).
        goToPage: (page) => showPage(page - 1),
        // How many pages currently exist / which one is showing — also
        // 1-indexed, also for admin/audit_logs.blade.php's "remember
        // where I was" marker (captured at the moment "View" is clicked,
        // not asked for here after the fact).
        getCurrentPage: () => currentPageIndex + 1,
    };
}
window.initFittedPagination = initFittedPagination;

/**
 * Intercepts clicks on pagination links (see vendor/pagination/custom.blade.php
 * — the app-wide custom pagination view) inside `container`, fetching
 * `opts.refreshUrl` with the CLICKED link's own query string instead of
 * letting the browser navigate. Fixes the same scroll-jump-to-top problem
 * decide()/decideBatch() had (see approver/dashboard.blade.php): a plain
 * pagination `<a>` causes a full page navigation, which always reloads
 * scrolled to the top.
 *
 * Swaps `container`'s innerHTML (reusing the exact same fragment route
 * every poll/channel refresh on that page already uses — both paginated
 * sections on a page like SLA Queue or ML Training render inside the SAME
 * shared container, and the clicked link's own href already carries BOTH
 * pagination params correctly merged — see AdminController::
 * paginateContainers()), then updates the visible URL via
 * history.pushState so back/forward/refresh/bookmark all still resolve to
 * the real page + that exact page number, not the fragment route. A
 * `popstate` listener (registered once, shared by every container that
 * calls this) re-syncs content on browser back/forward, since pushState
 * alone only changes the address bar, not the DOM.
 *
 * @param {Element} container - swapped via innerHTML, same as applyLiveRefresh's opts.target
 * @param {Object} opts
 * @param {string} opts.refreshUrl - returns an HTML fragment (this page's own .../refresh route)
 * @param {Function} [opts.onSwap] - called after a successful swap
 */
const __ajaxPaginationContainers = [];

function __refreshAjaxPaginationContainer(container, opts, search) {
    // A page-number click is exactly the "clicked something, now
    // waiting" case trackSlowOp() exists for — a plain fetch, no
    // WebSocket involved, so the connection banner's Echo check alone
    // would never catch a slow one.
    const stopSlowOp = trackSlowOp();

    fetch(opts.refreshUrl + search, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            container.innerHTML = html;
            if (opts.onSwap) opts.onSwap();
        })
        .catch(() => { window.location.reload(); }) // fetch failed — fall back to a real navigation rather than leave stale content up
        .finally(stopSlowOp);
}

function enableAjaxPagination(container, opts) {
    __ajaxPaginationContainers.push({ container, opts });

    container.addEventListener('click', function (e) {
        const link = e.target.closest('a[rel="prev"], a[rel="next"], a[aria-label^="Go to page"]');
        if (!link || !link.closest('nav[aria-label="Pagination Navigation"]')) return;

        e.preventDefault();
        const url = new URL(link.href, window.location.href);
        // link.href's pathname is already the real page route, not the
        // fragment route — every paginator in this app is built with the
        // real route() as its `path` specifically so this holds.
        history.pushState({}, '', url.pathname + url.search);
        __refreshAjaxPaginationContainer(container, opts, url.search);
    });
}
window.enableAjaxPagination = enableAjaxPagination;

window.addEventListener('popstate', () => {
    __ajaxPaginationContainers.forEach(({ container, opts }) => {
        __refreshAjaxPaginationContainer(container, opts, window.location.search);
    });
});

/**
 * Chat widget (Feature: floating icon to message the single Admin, and
 * for Admin to message any user). Tracks its own currently-open view
 * client-side (list/thread/media + which other user) so a live push or
 * poll tick re-fetches exactly what's on screen instead of guessing.
 */
let __chatState = { view: 'list', withId: null };

function __chatCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

function __chatRefreshUrl() {
    const widget = document.getElementById('chat-widget');
    if (!widget) return null;

    const base = widget.dataset.refreshUrl;
    const params = new URLSearchParams();
    if (__chatState.withId) params.set('with', __chatState.withId);
    if (__chatState.view === 'media') params.set('view', 'media');
    if (__chatState.query) params.set('q', __chatState.query);

    const qs = params.toString();
    return qs ? `${base}?${qs}` : base;
}

function __chatLoadPanel() {
    const url = __chatRefreshUrl();
    const body = document.getElementById('chat-panel-body');
    if (!url || !body) return;

    fetch(url, { headers: { Accept: 'text/html' } })
        .then((res) => (res.ok ? res.text() : Promise.reject(res)))
        .then((html) => {
            body.innerHTML = html;
            const messages = document.getElementById('chat-messages');
            if (messages) messages.scrollTop = messages.scrollHeight;
        })
        .catch(() => {});

    // Opening a thread IS the read receipt for it, same convention as the
    // notification bell marking everything read on open.
    if (__chatState.view === 'thread' && __chatState.withId) {
        fetch(`/chat/thread/${__chatState.withId}/read`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': __chatCsrfToken(), Accept: 'application/json' },
        })
            .then(() => __chatUpdateBadge())
            .catch(() => {});
    }
}

window.openChatUserList = function () {
    __chatState = { view: 'list', withId: null };
    __chatLoadPanel();
};

window.openChatThread = function (otherId) {
    __chatState = { view: 'thread', withId: otherId };
    __chatLoadPanel();
};

window.openChatMedia = function (otherId) {
    __chatState = { view: 'media', withId: otherId };
    __chatLoadPanel();
};

window.searchChatThread = function (otherId, query) {
    __chatState = { view: 'thread', withId: otherId, query };
    __chatLoadPanel();
};

window.previewChatImage = function () {
    const input = document.getElementById('chat-image-input');
    const label = document.getElementById('chat-image-preview-name');
    if (!input || !label) return;

    if (input.files && input.files[0]) {
        label.textContent = `📎 ${input.files[0].name}`;
        label.classList.remove('hidden');
    } else {
        label.classList.add('hidden');
    }
};

window.sendChatMessage = function (event, otherId) {
    event.preventDefault();
    const form = event.target;
    const bodyInput = document.getElementById('chat-body-input');
    const imageInput = document.getElementById('chat-image-input');
    const text = bodyInput ? bodyInput.value.trim() : '';

    if (!text && !(imageInput && imageInput.files && imageInput.files[0])) {
        return false; // nothing to send
    }

    const widget = document.getElementById('chat-widget');
    const formData = new FormData();
    formData.append('with', otherId);
    if (text) formData.append('body', text);
    if (imageInput && imageInput.files && imageInput.files[0]) {
        formData.append('image', imageInput.files[0]);
    }

    fetch(widget.dataset.sendUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': __chatCsrfToken(), Accept: 'application/json' },
        body: formData,
    })
        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
        .then(() => {
            form.reset();
            const label = document.getElementById('chat-image-preview-name');
            if (label) label.classList.add('hidden');
            __chatLoadPanel();
        })
        .catch(() => {});

    return false;
};

function __chatRenderBadge(count) {
    const badge = document.getElementById('chat-unread-badge');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count > 9 ? '9+' : count;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

function __chatUpdateBadge() {
    const widget = document.getElementById('chat-widget');
    if (!widget) return;

    fetch(widget.dataset.pollUrl, { headers: { Accept: 'application/json' } })
        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
        .then((data) => __chatRenderBadge(data.unread_count))
        .catch(() => {});
}

/**
 * Not built on startLiveChannel()/startLivePoll() — those two are shaped
 * around swapping one HTML fragment into one fixed target, but the chat
 * badge's payload is plain JSON and needs to trigger a re-fetch of
 * whichever of THREE different views (list/thread/media) happens to be
 * open, not one fixed target. A small dedicated loop here is simpler and
 * more correct than bending isBusy() into a side-effect hook, which
 * (tried first) broke startLivePoll's own change-detection by never
 * letting it record a new baseline.
 */
function initChatWidget() {
    const widget = document.getElementById('chat-widget');
    if (!widget) return;

    __chatUpdateBadge();

    if (window.Echo) {
        window.Echo.private(`user.${widget.dataset.userId}`).listen('.chat.message.sent', () => {
            __chatUpdateBadge();
            if (widget.open) __chatLoadPanel();
        });
    }

    let lastUnread = null;
    const poll = () => {
        fetch(widget.dataset.pollUrl, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                __chatRenderBadge(data.unread_count);
                if (lastUnread !== null && data.unread_count !== lastUnread && widget.open) {
                    __chatLoadPanel();
                }
                lastUnread = data.unread_count;
            })
            .catch(() => {})
            .finally(() => setTimeout(poll, (30 + Math.random() * 15) * 1000));
    };
    setTimeout(poll, (30 + Math.random() * 15) * 1000);

    // Default view on open: Admin sees the full user list (or whatever
    // was last open), everyone else (exactly one possible contact) goes
    // straight into that thread.
    widget.addEventListener('toggle', () => {
        if (!widget.open) return;

        if (widget.dataset.isAdmin === '1') {
            __chatLoadPanel();
        } else {
            window.openChatThread(parseInt(widget.dataset.adminId, 10));
        }
    });
}
