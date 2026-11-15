{{-- The legacy shell's notification bell (Livewire) inside dot-ui's panel bell (R-52). dot-ui owns open and close (click, Escape, outside click, one menu at a time, the
     phone sheet); this component loads the latest ten when the panel is first opened (dot:menu-open) and marks them read. resources/js/eh/header.js keeps the panel open
     across a re-render. --}}
<div class="eh-bell" x-data x-on:dot:menu-open.once="$wire.load()">
    <x-dot.bell :count="$this->unreadCount" id="notifications-panel" panel-label="Notifications">
        <div class="dh-panel__head">
            <span class="dh-panel__title">Notifications</span>
            @if ($this->unreadCount > 0)
                <button type="button" class="dh-mark" wire:click="markAllAsRead">Mark all read</button>
            @endif
        </div>
        <div class="dh-panel__list">
            @if (! $loaded)
                <p class="dh-panel__empty" role="status">Loading</p>
            @else
                @forelse ($this->notifications as $notification)
                    <button type="button" class="dh-panel__item" @if (! $notification->read_at) data-unread @endif wire:click="markAsRead('{{ $notification->id }}')" wire:key="n-{{ $notification->id }}">
                        <span class="eh-bell__text"><span class="eh-bell__title">@unless ($notification->read_at)<span class="eh-sr-only">Unread: </span>@endunless{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <span class="eh-bell__msg">{{ $notification->data['message'] ?? '' }}</span></span>
                    </button>
                @empty
                    <p class="dh-panel__empty">No notifications yet.</p>
                @endforelse
            @endif
        </div>
    </x-dot.bell>
</div>
