{{--
    Figures in one sentence row: items is a list of ['value' => ..., 'label' => ...]. A null value prints a dash and
    says "not available"; zero prints 0. Never metric tiles.
--}}
@props(['items' => [], 'label' => null])
<dl {{ $attributes->class('eh-statline') }} @if($label) aria-label="{{ $label }}" @endif>
    @foreach ($items as $item)
        <div class="eh-statline__item">
            <dt>{{ $item['label'] }}</dt>
            @if ($item['value'] === null)
                <dd data-empty="true"><span aria-hidden="true">&#8212;</span><span class="eh-sr-only">not available</span></dd>
            @else
                <dd>{{ $item['value'] }}</dd>
            @endif
        </div>
    @endforeach
</dl>
