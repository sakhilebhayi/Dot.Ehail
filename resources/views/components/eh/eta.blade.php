{{-- ETA: duration in seconds, optional arrival time. size: md | lg. --}}
@props(['seconds', 'at' => null, 'size' => 'md', 'prefix' => null])
@php
    $short = \App\Support\Ui\Duration::short((int) $seconds);
    $spoken = \App\Support\Ui\Duration::spoken((int) $seconds);
    $atText = $at ? \Illuminate\Support\Carbon::parse($at)->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i') : null;
@endphp
<span {{ $attributes->class('eh-eta'.($size === 'lg' ? ' eh-eta--lg' : '')) }} aria-label="{{ trim(($prefix ? $prefix.' ' : '').$spoken.($atText ? ', arriving at '.$atText : '')) }}">
    <span aria-hidden="true">@if($prefix){{ $prefix }} @endif<span class="eh-eta__value">{{ $short }}</span></span>
    @if ($atText)<span class="eh-eta__at" aria-hidden="true">arrives {{ $atText }}</span>@endif
</span>
