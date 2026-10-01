@props(['document', 'showReviewers' => true])

{{--
    This viewer's own cumulative review time on this document, plus
    (optionally) a "who's currently reviewing this" cluster — both built
    from DocumentReviewSession (see app/Models/DocumentReviewSession.php).
    Uses the same native <details>/<summary> click-to-reveal pattern
    already used elsewhere in this app (notification bell, workflow
    config) rather than introducing a JS framework dependency.

    showReviewers=false (Approver queue) turns off the reviewing cluster
    here specifically, since it duplicated — on a much staler 15-minute
    window — the genuinely live one now built into the document viewer
    modal itself (document-viewer-modal.blade.php); "Your review time"
    stays since that's a different, self-facing feature.
--}}
@php
    $activeViewers = $showReviewers ? \App\Models\DocumentReviewSession::active()
        ->where('document_id', $document->document_id)
        ->with('user')
        ->get()
        ->unique('user_id') : collect();

    $currentUser = auth()->user();
    $myTotalSeconds = $currentUser ? \App\Models\DocumentReviewSession::totalSecondsFor($document->document_id, $currentUser->user_id) : 0;
    $mySessionCount = $currentUser ? \App\Models\DocumentReviewSession::where('document_id', $document->document_id)
        ->where('user_id', $currentUser->user_id)->whereNotNull('closed_at')->count() : 0;

    $fmt = function (int $seconds) {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    };
@endphp

<div class="flex items-center gap-3 flex-wrap">
    @if($activeViewers->isNotEmpty())
        <details class="relative inline-block">
            <summary class="list-none cursor-pointer flex items-center -space-x-1.5">
                @foreach($activeViewers->take(4) as $session)
                    <span class="w-5 h-5 rounded-full bg-approved-500 text-white text-[9px] font-bold flex items-center justify-center ring-2 ring-white shadow-sm"
                          title="{{ $session->user->full_name ?? 'Unknown' }} is currently reviewing">
                        {{ strtoupper(substr($session->user->full_name ?? '?', 0, 1)) }}
                    </span>
                @endforeach
                <span class="ml-2 text-[11px] text-approved-700 font-medium flex items-center gap-1">
                    <span class="relative flex w-1.5 h-1.5">
                        <span class="absolute inline-flex h-full w-full rounded-full bg-approved-400 opacity-75 animate-ping"></span>
                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-approved-500"></span>
                    </span>
                    {{ $activeViewers->count() }} reviewing now
                </span>
            </summary>
            <div class="absolute z-10 mt-1 left-0 bg-white rounded-lg shadow-lg border border-surface-200 py-1.5 min-w-[200px]">
                @foreach($activeViewers as $session)
                    <div class="px-3 py-1.5 text-xs">
                        <p class="font-medium text-surface-800">{{ $session->user->full_name ?? 'Unknown' }}</p>
                        <p class="text-[10px] text-surface-400">
                            {{ $session->user?->displayRole() ?? '' }}
                            @if($session->user->assigned_category) &middot; {{ $session->user->assigned_category }} @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if($currentUser && $currentUser->isApprover() && $mySessionCount > 0)
        <span class="text-[11px] text-surface-400">
            Your review time: <span class="font-medium text-surface-600">{{ $fmt($myTotalSeconds) }}</span>
        </span>
    @endif
</div>
