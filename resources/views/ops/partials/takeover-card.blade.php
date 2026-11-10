{{-- One incident rail card; also the template live.js fills (data-sos-* hooks). --}}
<li class="ops-sos__card" @if(! $item['owner']) data-unowned @endif>
    <span class="ops-sos__beacon" aria-hidden="true"></span>
    <div class="ops-sos__main">
        <p class="ops-sos__what"><a href="{{ $item['href'] }}" data-sos-link>{{ $item['kind'] }} {{ $item['reference'] }}</a></p>
        <p class="ops-sos__who"><span data-sos-who>{{ $item['who'] }}@if($item['ride_id']) · Ride {{ $item['ride_id'] }}@endif </span><span class="eh-plate" data-sos-plate @if(! $item['vehicle']) hidden @endif>{{ $item['vehicle'] }}</span></p>
        <p class="ops-sos__where"><x-eh.icon name="pin" /><span data-sos-place>{{ $item['place'] }}</span></p>
    </div>
    <div class="ops-sos__state">
        <p class="ops-sos__owner" data-sos-owner>{{ $item['owner_text'] }}</p>
        <p class="ops-sos__level" data-sos-level>{{ $item['level_text'] }}@if($item['next_text']) · {{ $item['next_text'] }}@endif</p>
        <p class="ops-sos__time" data-sos-time>{{ $item['opened_text'] }}</p>
    </div>
    <x-eh.button size="sm" :variant="$item['owner'] ? 'secondary' : 'danger'" :href="$item['href'] ?: '#'" data-sos-open>{{ $item['owner'] ? 'Open' : 'Open and take it' }}</x-eh.button>
</li>
