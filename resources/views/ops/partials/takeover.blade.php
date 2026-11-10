{{-- The incident rail (R-46 polish, D-2202): every open SOS and critical incident takes over the top of the live board.
     resources/js/ops/live.js redraws the same structure from the feed; the alarm sound needs a click (browser rule). --}}
@php($unowned = collect($items)->whereNull('owner')->count())
<section class="ops-sos" data-live-takeover aria-labelledby="sos-h" @if(empty($items)) hidden @endif>
    <div class="ops-sos__head">
        <h2 id="sos-h" class="ops-sos__title"><x-eh.icon name="siren" /><span data-sos-title>{{ count($items) }} {{ \Illuminate\Support\Str::plural('emergency', count($items)) }} open{{ $unowned ? ', '.$unowned.' with nobody owning '.($unowned === 1 ? 'it' : 'them') : '' }}</span></h2>
        <div class="ops-sos__sound">
            <button type="button" class="eh-btn eh-btn--secondary eh-btn--sm" data-sos-sound aria-pressed="false"><span data-sos-icon-off><x-eh.icon name="bell-off" /></span><span data-sos-icon-on hidden><x-eh.icon name="bell" /></span><span class="eh-btn__label" data-sos-sound-label>Alarm sound off</span></button>
            <span class="ops-sos__hint" data-sos-sound-hint>Turn it on to hear new emergencies. Browsers play sound only after a click on the page, so turn it on again after a reload.</span>
        </div>
    </div>
    <ol class="ops-sos__list" data-sos-list>
        @foreach ($items as $item)
            @include('ops.partials.takeover-card', ['item' => $item])
        @endforeach
    </ol>
    <template data-sos-card>@include('ops.partials.takeover-card', ['item' => ['owner' => null, 'href' => '', 'kind' => '', 'reference' => '', 'who' => '', 'ride_id' => null, 'vehicle' => null, 'place' => '', 'owner_text' => '', 'level_text' => '', 'next_text' => null, 'opened_text' => '']])</template>
</section>
