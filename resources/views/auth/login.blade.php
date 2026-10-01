<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In — DocTrack</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-primary-950 font-sans">
<div class="min-h-full flex items-center justify-center px-4 relative overflow-hidden">
    {{-- Soft ambient glow behind the card — restrained, not a flashy hero,
         just enough depth so the dark background doesn't read as flat. --}}
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_50%_at_50%_0%,theme(colors.primary.700/0.35),transparent)]"></div>

    <div class="w-full max-w-sm relative">
        <div class="text-center mb-8">
            <img src="{{ asset('images/logo.png') }}" alt="DocTrack logo" class="w-14 h-14 rounded-2xl mx-auto shadow-elevated ring-1 ring-white/20 object-cover">
            <h1 class="mt-5 text-xl font-semibold text-white tracking-tight">Document Classification &amp; Tracking</h1>
            <p class="text-sm text-primary-300 mt-1.5">UJF Corporation — Internal System</p>
        </div>

        <div class="bg-white rounded-2xl shadow-elevated p-8 ring-1 ring-black/5">
            @if(session('status'))
                <div class="mb-5 rounded-xl bg-approved-50 border border-approved-500/25 text-approved-700 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div id="login-message"
                @if(session('login_retry_after')) data-retry-after="{{ session('login_retry_after') }}" @endif
                class="{{ session('login_retry_after') || $errors->any() ? '' : 'hidden' }} mb-5 rounded-xl px-4 py-3 text-sm
                    {{ session('login_retry_after') ? 'bg-rejected-50 border border-rejected-500/25 text-rejected-700' : (str_contains($errors->first(), 'attempt') ? 'bg-processing-50 border border-processing-500/25 text-processing-700' : 'bg-rejected-50 border border-rejected-500/25 text-rejected-700') }}">
                @if(session('login_retry_after'))
                    Too many login attempts. Try again in <span id="login-throttle-seconds" class="font-semibold">{{ session('login_retry_after') }}</span> second(s).
                @else
                    {{ $errors->first() }}
                @endif
            </div>

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-surface-700 mb-1.5">Email</label>
                    {{-- type="text", not "email" — deliberately: browser-native
                         email validation intercepts an invalid value BEFORE the
                         form ever submits, showing its own small tooltip instead
                         of this app's error banner — easy to miss and
                         inconsistent with every other validation error here.
                         Letting the server's own 'email' rule catch it instead
                         guarantees one consistent, visible error every time. --}}
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-surface-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0-.621.504-1.125 1.125-1.125h17.25c.621 0 1.125.504 1.125 1.125v10.5c0 .621-.504 1.125-1.125 1.125H3.375A1.125 1.125 0 012.25 17.25V6.75z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.586 6.343l9 6.3a.75.75 0 00.828 0l9-6.3" />
                            </svg>
                        </span>
                        <input id="email" name="email" type="text" inputmode="email" required autofocus value="{{ old('email') }}"
                            class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm pl-10 pr-3.5 py-2.5">
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-sm font-medium text-surface-700">Password</label>
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-primary-700 hover:underline">Forgot password?</a>
                    </div>
                    <x-password-input id="password" required />
                </div>

                <button type="submit" id="login-submit"
                    class="w-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-medium text-sm py-2.5 rounded-lg shadow-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                    Sign In
                </button>
            </form>
        </div>
        <p class="text-center text-xs text-primary-400 mt-6">Access is role-restricted. Contact your Administrator for an account.</p>
    </div>
</div>
<script>
    // Live-ticking countdown for the login LOCKOUT message
    // (AuthController's per-email+IP throttle) — the server only sends
    // the seconds remaining as of the response; without this, that
    // number would sit frozen and visibly wrong for however long the
    // user actually waits.
    (function () {
        const messageBox = document.getElementById('login-message');
        const submitBtn = document.getElementById('login-submit');

        const throttleRetryAfter = messageBox.dataset.retryAfter;
        if (!throttleRetryAfter) return;

        let remaining = parseInt(throttleRetryAfter, 10);
        const secondsEl = document.getElementById('login-throttle-seconds');
        submitBtn.disabled = true;

        const timer = setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                messageBox.className = 'mb-5 rounded-xl px-4 py-3 text-sm bg-approved-50 border border-approved-500/25 text-approved-700';
                messageBox.textContent = 'You can try again now.';
                submitBtn.disabled = false;
                clearInterval(timer);
                return;
            }
            secondsEl.textContent = remaining;
        }, 1000);
    })();
</script>
</body>
</html>
