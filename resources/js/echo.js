// Reverb speaks the Pusher protocol, so Echo is configured with the
// 'reverb' broadcaster (a thin preset over the same client) rather than a
// third-party Pusher account — this is Laravel's own first-party WebSocket
// server, no external SaaS dependency.
import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Private channels (every channel in routes/channels.php is private) POST
// to /broadcasting/auth to prove the visitor is who they claim before
// Reverb allows the subscription. That route sits behind Laravel's normal
// CSRF protection like any other POST, so the request needs the token —
// without this `auth.headers` block, the browser's subscription silently
// fails 419 and no live update (dashboards OR the notification bell) ever
// arrives, since there's no `window.axios` configured anywhere in this
// app to inject the header automatically the way a stock Laravel
// bootstrap.js would.
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

// wsHost: prefer the build-time VITE_REVERB_HOST, but only when it's a
// real, distinct hostname — fall back to window.location.hostname when
// it's a same-machine placeholder (localhost/127.0.0.1).
//
// Two deployment shapes need to both work here:
//   1. Local self-hosting — Reverb runs on the SAME machine as the web
//      app, just a different port. REVERB_HOST is baked in as
//      "localhost" at build time, which means something different
//      depending on which device loads the page (on a phone, "localhost"
//      means the phone, not the server). window.location.hostname fixes
//      this — it always resolves to whatever address the browser
//      actually used to reach the page (127.0.0.1, localhost, or a LAN
//      IP), which is correct since Reverb listens on 0.0.0.0 there too.
//   2. Split hosting (e.g. Railway) — Reverb runs as a genuinely
//      SEPARATE service with its own distinct public domain, different
//      from the web app's domain. Here window.location.hostname is
//      actively wrong (it's the web app's own domain, which doesn't
//      speak the Reverb protocol) — the real, baked-in VITE_REVERB_HOST
//      must be used as-is.
const buildTimeHost = import.meta.env.VITE_REVERB_HOST;
const isSameHostPlaceholder = !buildTimeHost || buildTimeHost === 'localhost' || buildTimeHost === '127.0.0.1';
const wsHost = isSameHostPlaceholder ? window.location.hostname : buildTimeHost;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Split hosting (e.g. Railway) routes this connection through the
    // platform's own edge proxy, which can close a WebSocket after a
    // shorter idle period than pusher-js's 120s default ping interval —
    // the proxy kills it before the client ever gets a chance to prove
    // it's alive, which is why the "Reconnecting" banner (app.js's
    // state_change handler) flickers on Railway but never on localhost
    // (a direct connection with no intermediary idle timeout). Pinging
    // every 30s instead keeps the socket active well before any such
    // proxy would consider it idle.
    activityTimeout: 30000,
    pingTimeout: 15000,
    auth: {
        headers: {
            'X-CSRF-TOKEN': csrfToken,
        },
    },
});
