<?php

use App\Http\Middleware\CheckForSlaOutage;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registers POST /broadcasting/auth (web+auth middleware — matches
    // this app's existing session-cookie auth, no separate token needed)
    // and loads routes/channels.php's Broadcast::channel() authorization
    // callbacks. Required for private-channel WebSocket auth (Reverb) to
    // work at all — see resources/js/echo.js for the client side.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register the RBAC alias used throughout routes/web.php
        // (e.g. ->middleware('role:admin')). This is the Laravel 11
        // equivalent of adding it to $middlewareAliases in Kernel.php.
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // The fast path for outage detection (see CheckForSlaOutage's
        // docblock) — appended to the 'web' group so it runs on every
        // request, not just scheduler ticks. Cheap no-op for the normal
        // case; only does real work the first time it notices a gap.
        $middleware->appendToGroup('web', CheckForSlaOutage::class);

        // Trust every proxy in front of this app (Railway, or any other
        // platform that terminates TLS at its own edge and forwards
        // requests to this container over plain HTTP). Without this,
        // Laravel has no way to know the original request was HTTPS, so
        // url()/asset()/Vite all generate http:// links — which the
        // browser then silently blocks as mixed content on an https:// page
        // (this is exactly what broke all CSS/JS on first deploy). Trusting
        // '*' is safe here specifically because the platform's edge is the
        // only way any traffic reaches this container — there's no direct
        // path for an external client to spoof X-Forwarded-* headers.
        $middleware->trustProxies(at: '*');
    })
    ->withSchedule(function (Schedule $schedule) {
        // Section 5 safety net. Primary detection is now event-driven —
        // EscalateAssignmentJob is dispatched with a delay set to exactly
        // each assignment's sla_expires_at (see WorkflowService), so a
        // breach is caught the instant it happens via the persistent queue
        // worker (see docuwise-queue-worker systemd user service), not on
        // a polling cycle.
        //
        // 1) backstop only: auto-approves any overdue seat the event-driven job
        // might ever miss (e.g. the queue worker was down when a job should have
        // fired). 5 minutes is plenty for a safety net that isn't the
        // primary mechanism anymore.
        // appendOutputTo: this command has been failing silently in
        // production (Railway scheduler logs show "FAIL" on every run,
        // but the default `> /dev/null 2>&1` redirect discards the
        // actual exception) — capturing output here until the real
        // cause is found and fixed, then this can be reverted.
        $schedule->command('workflow:check-parallel-slas')->everyFiveMinutes()->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler-parallel-slas.log'));
        // 2) the follow-ups that genuinely need a schedule rather than an
        // event: outage detection/compensation, the Admin's late-review
        // reminders for auto-approvals still awaiting review, and the
        // one-time "final call" reminder to an approver about to miss a
        // deadline. It does NOT auto-approve missed deadlines — that is
        // (1) above and the delayed job.
        $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
        // Note: queue draining is no longer scheduled here — a persistent
        // `php artisan queue:work` process (systemd user service) runs
        // continuously instead, which is what makes EscalateAssignmentJob
        // actually fire in real time rather than up to a minute late.

        // Section 3 hardware requirement: nightly backup of the database
        // + document/ML-model storage (see BackupSystem's docblock — this
        // project has already lost both to out-of-band resets once each).
        // Low-traffic hour; overlap-safe in case a manual run is mid-flight.
        $schedule->command('backup:run')->dailyAt('02:00')->withoutOverlapping();

        // Exports rows past their retention window to a compressed file
        // under storage/app/archives/, then removes them from the live
        // table — keeps notification_records from growing forever
        // without ever silently losing the data (see ArchiveOldRecords's
        // docblock). audit_logs and document_review_sessions are both
        // deliberately excluded — pruning either would silently shorten
        // old documents' Document Tracker history. Runs after the
        // nightly backup, same low-traffic window.
        $schedule->command('records:archive')->dailyAt('02:30')->withoutOverlapping();

        // Fully automatic — no admin "train now" button by design (see
        // ApprovalTimeMlService). Hourly is frequent enough to pick up a
        // newly-eligible (category, department) combo without being
        // wasteful; trainFor() itself is cheap to skip when nothing's
        // actually changed enough to beat the currently active model.
        $schedule->command('ml:train-time-estimator')->hourly()->withoutOverlapping();

        // Fully automatic classifier retraining, same "no admin button"
        // design as above (see WorkflowService::ingest()'s $isAmbiguous
        // docblock). Every 5 minutes since real company document volume
        // moves fast (see config('ml.auto_train_check_interval_minutes'))
        // — the check itself is cheap; ClassificationService::
        // autoTrainIfDue() only actually retrains once a real batch-size
        // or age trigger is met.
        $schedule->command('ml:auto-train-classifier')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Sends every unhandled exception to Sentry (config/sentry.php,
        // SENTRY_LARAVEL_DSN env var) — a no-op with no DSN set (e.g.
        // local dev), so this is safe to leave wired in unconditionally.
        // Without this, the only trace of a production error is
        // storage/logs/laravel.log on whichever ephemeral Railway
        // container happens to still be running.
        Integration::handles($exceptions);
    })->create();
