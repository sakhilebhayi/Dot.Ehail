{{--
    Stepper drawn as a route line with stops. steps: list of ['label' => ..., 'meta' => ...]; current: index of the
    stop in progress (earlier stops are done). orientation: vertical | horizontal. stateful=false draws
    every stop the same (a description of a journey rather than progress through one).
--}}
@props(['steps' => [], 'current' => 0, 'orientation' => 'vertical', 'label' => 'Progress', 'stateful' => true])
<ol {{ $attributes->class('eh-stepper'.($orientation === 'horizontal' ? ' eh-stepper--horizontal' : '')) }} aria-label="{{ $label }}">
    @foreach ($steps as $index => $step)
        @php($state = ! $stateful ? 'stop' : ($index < $current ? 'done' : ($index === $current ? 'current' : 'todo')))
        <li class="eh-stepper__step" data-state="{{ $state }}" @if($state === 'current') aria-current="step" @endif>
            <span class="eh-stepper__dot">@if($state === 'done')<x-eh.icon name="check" />@endif</span>
            <span class="eh-stepper__body">
                <span class="eh-stepper__label">{{ $step['label'] }}@if($stateful)<span class="eh-sr-only">, {{ ['done' => 'done', 'current' => 'in progress', 'todo' => 'not started'][$state] }}</span>@endif</span>
                @if (! empty($step['meta']))<span class="eh-stepper__meta" style="display:block">{{ $step['meta'] }}</span>@endif
            </span>
        </li>
    @endforeach
</ol>
