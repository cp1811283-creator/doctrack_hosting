{{-- The folder tiles themselves — split out from archive/index.blade.php
     so a live/poll refresh (see the Echo listener + setInterval near the
     bottom of that file) can swap just this content back in without
     touching #archive-folder-card itself, the same id sizeFolderGrid()
     measures against.
     Expects: $folders, $isOwnSubmissionsView. --}}
<h2 class="text-sm font-semibold text-surface-900 mb-3">
    Browse by Category
    @if($isOwnSubmissionsView)
        <span class="text-xs font-normal text-surface-400">— your own approved submissions</span>
    @endif
</h2>
{{-- Fixed 2-column grid (not a responsive 2/3/4-column one) so
     each tile gets a whole half-width column to grow into. --}}
<div class="folder-grid grid grid-cols-2 gap-8">
    @foreach($folders as $folder)
        <a href="{{ url()->current() }}?category={{ urlencode($folder->category) }}" class="group block">
            {{-- Two rounded pieces (tab + body), not a clip-path
                 polygon — clip-path only does straight-line corners,
                 which read as "pointy" rather than a real folder.
                 Gradients on both pieces give it depth instead of a
                 flat fill. Same blue gradient as the sidebar's "D"
                 logo badge (layouts/app.blade.php) — from-primary-400
                 to-primary-600 — for brand consistency. --}}
            <div class="folder-tile-tab w-40 h-10 ml-8 rounded-t-lg bg-gradient-to-br from-primary-300 to-primary-500 group-hover:from-primary-400 group-hover:to-primary-600 transition-colors"></div>
            <div class="folder-tile-body -mt-px h-64 rounded-b-xl rounded-tr-xl bg-gradient-to-br from-primary-400 to-primary-600 group-hover:from-primary-500 group-hover:to-primary-700 shadow-lg group-hover:shadow-xl group-hover:-translate-y-0.5 transition-all flex flex-col items-center justify-center text-center px-4">
                <h3 class="text-xl font-semibold text-white drop-shadow-sm">{{ $folder->category }}</h3>
                <p class="text-sm text-primary-100 mt-1">{{ $folder->total }} document{{ $folder->total === 1 ? '' : 's' }}</p>
                @if($folder->disputed > 0 || $folder->auto_approved > 0)
                    <div class="flex flex-wrap justify-center gap-1.5 mt-3">
                        @if($folder->disputed > 0)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white text-processing-700">{{ $folder->disputed }} disputed</span>
                        @endif
                        @if($folder->auto_approved > 0)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white text-approved-700">{{ $folder->auto_approved }} auto-approved</span>
                        @endif
                    </div>
                @endif
            </div>
        </a>
    @endforeach
</div>
