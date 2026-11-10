{{-- Money from integer minor units. size: md | lg | xl. Tabular figures; screen readers hear "minus" for negatives. --}}
@props(['minor', 'currency' => 'ZAR', 'size' => 'md', 'signed' => false, 'muted' => false, 'whole' => false])
@php
    $amount = (int) $minor;
    $text = \App\Support\Ui\MoneyFormatter::format($amount, $currency, $signed, $whole);
    $classes = 'eh-money'.($size !== 'md' ? ' eh-money--'.$size : '').($muted ? ' eh-money--muted' : '').($amount < 0 ? ' eh-money--negative' : '');
@endphp
<data value="{{ $amount }}" {{ $attributes->class($classes) }} data-currency="{{ strtoupper($currency) }}" @if($amount < 0) aria-label="{{ \App\Support\Ui\MoneyFormatter::spoken($amount, $currency) }}" @endif>{{ $text }}</data>
