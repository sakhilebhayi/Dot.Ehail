{{--
    Empty state: says what will land here and what to do now. The mark is the motif: a line from a stop to a stop,
    dashed while the destination is not built yet (pending) and solid when the person simply has nothing yet.
--}}
@props(['title', 'pending' => false, 'headingLevel' => 2])
<section {{ $attributes->class('eh-empty') }}>
    <span class="eh-empty__mark" aria-hidden="true"><span class="eh-empty__stop"></span><span class="eh-empty__line {{ $pending ? 'eh-empty__line--pending' : '' }}"></span><span class="eh-empty__stop eh-empty__stop--end"></span></span>
    <h{{ $headingLevel }} class="eh-empty__title">{{ $title }}</h{{ $headingLevel }}>
    @if (trim($slot) !== '')<div class="eh-empty__body">{{ $slot }}</div>@endif
    @isset($actions)<div class="eh-empty__actions">{{ $actions }}</div>@endisset
</section>
