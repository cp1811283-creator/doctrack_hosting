<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — DocTrack</title>
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
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_50%_at_50%_0%,theme(colors.primary.700/0.35),transparent)]"></div>

    <div class="w-full max-w-sm relative">
        <div class="text-center mb-8">
            <img src="{{ asset('images/logo.png') }}" alt="DocTrack logo" class="w-14 h-14 rounded-2xl mx-auto shadow-elevated ring-1 ring-white/20 object-cover">
            <h1 class="mt-5 text-xl font-semibold text-white tracking-tight">Reset Your Password</h1>
            <p class="text-sm text-primary-300 mt-1.5">Enter the email on your account and we'll send you a reset link.</p>
        </div>

        <div class="bg-white rounded-2xl shadow-elevated p-8 ring-1 ring-black/5">
            @if(session('status'))
                <div class="mb-5 rounded-xl bg-approved-50 border border-approved-500/25 text-approved-700 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-5 rounded-xl bg-rejected-50 border border-rejected-500/25 text-rejected-700 px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-surface-700 mb-1.5">Email</label>
                    <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                        class="w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm px-3.5 py-2.5">
                </div>
                <button type="submit"
                    class="w-full bg-gradient-to-b from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-medium text-sm py-2.5 rounded-lg shadow-sm transition-all">
                    Send Reset Link
                </button>
            </form>
        </div>
        <p class="text-center text-xs text-primary-400 mt-6"><a href="{{ route('login') }}" class="hover:underline">&larr; Back to Sign In</a></p>
    </div>
</div>
</body>
</html>
