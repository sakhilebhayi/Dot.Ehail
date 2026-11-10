{{-- Column chart (App\Domain\Ops\Insights\ColumnChart). $chart, $title (accessible name). The table under it carries every value. --}}
@if ($chart['empty'])
    <p class="ops-none">{{ $empty ?? 'Nothing recorded in this period.' }}</p>
@else
<svg class="ops-chart" viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img" aria-label="{{ $title }}" preserveAspectRatio="xMidYMid meet">
    @foreach ($chart['ticks'] as $tick)
        <line class="{{ $loop->first ? 'base' : 'grid' }}" x1="{{ $chart['plot']['x'] }}" x2="{{ $chart['plot']['x'] + $chart['plot']['w'] }}" y1="{{ round($tick['y'], 2) }}" y2="{{ round($tick['y'], 2) }}" />
        <text class="tick" x="{{ $chart['plot']['x'] - 8 }}" y="{{ round($tick['y'] + 4, 2) }}" text-anchor="end">{{ $tick['label'] }}</text>
    @endforeach
    @foreach ($chart['bars'] as $bar)
        @if ($bar['axis'])<text class="tick" x="{{ round($bar['cx'], 2) }}" y="{{ $chart['height'] - 8 }}" text-anchor="middle">{{ $bar['axis'] }}</text>@endif
        <rect class="hit" x="{{ round($bar['hit']['x'], 2) }}" y="{{ $bar['hit']['y'] }}" width="{{ round($bar['hit']['w'], 2) }}" height="{{ $bar['hit']['h'] }}" tabindex="0" data-tip-value="{{ $bar['display'] }}" data-tip-label="{{ $bar['label'] }}"><title>{{ $bar['label'] }}: {{ $bar['display'] }}</title></rect>
        @if ($bar['path'])<path class="bar" d="{{ $bar['path'] }}" />@endif
    @endforeach
</svg>
@endif
