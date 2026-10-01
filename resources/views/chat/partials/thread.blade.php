{{--
    One conversation — every message between the current user and $other,
    oldest first. Admin gets a Back button to return to the user list;
    everyone else has only ever this one thread, so no back button.
--}}
<div class="px-4 py-3 border-b border-surface-200 flex items-center gap-2 shrink-0">
    @if(auth()->user()->isAdmin())
        <button type="button" onclick="openChatUserList()" class="p-1 -ml-1 rounded-lg hover:bg-surface-100 text-surface-500" aria-label="Back to conversations">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-surface-900 truncate">{{ $other->full_name }} ({{ $other->displayRole() }})</p>
        <p class="text-xs {{ $other->isOnline() ? 'text-approved-700' : 'text-surface-400' }}">{{ $other->isOnline() ? 'Online' : 'Offline' }}</p>
    </div>
    <button type="button" onclick="openChatMedia({{ $other->user_id }})" class="p-1.5 rounded-lg hover:bg-surface-100 text-surface-500" title="Photos" aria-label="View photos">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    </button>
</div>
<div class="px-3 py-2 border-b border-surface-100 shrink-0">
    <input type="text" id="chat-search-input" placeholder="Search this conversation…" value="{{ $keyword }}"
        class="w-full rounded-lg border-surface-300 text-xs px-3 py-1.5 focus:border-primary-500 focus:ring-primary-500"
        onkeydown="if(event.key==='Enter'){event.preventDefault(); searchChatThread({{ $other->user_id }}, this.value);}">
</div>
<div id="chat-messages" class="flex-1 min-h-0 overflow-y-auto px-3 py-3 space-y-2">
    @forelse($messages as $m)
        @php $mine = $m->sender_id === auth()->id(); @endphp
        <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[75%] {{ $mine ? 'bg-primary-700 text-white' : 'bg-surface-100 text-surface-800' }} rounded-2xl px-3 py-2">
                @if($m->attachment_path)
                    <a href="{{ route('chat.image', $m) }}" target="_blank">
                        <img src="{{ route('chat.image', $m) }}" class="rounded-lg max-w-full max-h-48 mb-1" alt="Attached image">
                    </a>
                @endif
                @if($m->body)
                    <p class="text-sm whitespace-pre-wrap break-words">{{ $m->body }}</p>
                @endif
                <p class="text-[10px] mt-1 {{ $mine ? 'text-primary-100' : 'text-surface-400' }}">{{ $m->created_at->format('g:i A, M j') }}</p>
            </div>
        </div>
    @empty
        <p class="text-center text-sm text-surface-400 py-8">{{ $keyword ? 'No messages match your search.' : 'No messages yet — say hello.' }}</p>
    @endforelse
</div>
<form id="chat-send-form" class="shrink-0 border-t border-surface-200 p-3 flex items-end gap-2" onsubmit="return sendChatMessage(event, {{ $other->user_id }})">
    <input type="file" id="chat-image-input" accept="image/png,image/jpeg" class="hidden" onchange="previewChatImage()">
    <button type="button" onclick="document.getElementById('chat-image-input').click()" class="p-2 rounded-lg hover:bg-surface-100 text-surface-500 shrink-0" aria-label="Attach image">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    </button>
    <textarea name="body" id="chat-body-input" rows="1" placeholder="Type a message…"
        class="flex-1 resize-none rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500"
        onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); this.form.requestSubmit();}"></textarea>
    <button type="submit" class="p-2 rounded-lg bg-primary-700 hover:bg-primary-800 text-white shrink-0" aria-label="Send message">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
    </button>
</form>
<p id="chat-image-preview-name" class="hidden px-3 pb-2 text-xs text-surface-500 shrink-0"></p>
