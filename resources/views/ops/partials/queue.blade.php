{{-- A live queue (items from App\Domain\Ops\Live\LiveBoard::items); the live script re-renders it from the feed. --}}
<ul class="ops-queue" data-live-queue="{{ $key }}" data-empty="{{ $empty }}">
    @forelse ($items as $item)
        <li>
            <a class="ops-queue__title" href="{{ $item['href'] }}">{{ $item['title'] }}</a>
            <span class="ops-queue__meta">{{ $item['meta'] }}</span>
            <span class="ops-queue__trail"><span class="ops-timer" data-tone="{{ $item['tone'] }}" @if($item['since']) data-ops-since="{{ $item['since'] }}" data-ops-warn="{{ $item['warn'] }}" data-ops-label="{{ $item['label'] }}" @endif>{{ $item['timer'] }}</span></span>
        </li>
    @empty
        <li><p class="ops-none" style="padding:0">{{ $empty }}</p></li>
    @endforelse
</ul>
