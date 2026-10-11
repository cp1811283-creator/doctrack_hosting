@props(['code'])

{{--
    Feature: tracking code — a short, human-readable reference
    (e.g. "JO-2026-0047") shown next to a document's title, with a copy
    button beside it. See TrackingCodeService's own docblock for why
    this exists: document titles have no uniqueness constraint, so two
    documents can share one with nothing to tell them apart on screen.

    Renders nothing at all for a document that never got a code (one
    that never reached classification — malware-blocked, extraction
    failed) rather than an empty/placeholder dash, since there's
    genuinely nothing to show or copy there.

    The copy button calls the one shared __docTrackCopyTrackingCode()
    in layouts/app.blade.php rather than wiring its own listener, so
    dozens of these on one page (an Archive results list, say) cost
    nothing extra.
--}}
@if($code)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-mono text-[11px] text-surface-500']) }}>
        {{ $code }}
        <button type="button"
            onclick="event.preventDefault(); event.stopPropagation(); __docTrackCopyTrackingCode(this, '{{ $code }}')"
            class="text-surface-400 hover:text-primary-700 transition-colors p-0.5 -m-0.5 rounded"
            title="Copy tracking code" aria-label="Copy tracking code {{ $code }}">
            <svg data-copy-icon class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            <svg data-check-icon class="w-3 h-3 hidden text-approved-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
        </button>
    </span>
@endif
