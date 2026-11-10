{{--
    "What we did": after an SOS, "I need help" or a safety report. Every line comes from the safety domain's
    notification rows with its real outcome; what was not done is said as plainly.
--}}
@php
    $tone = fn ($status) => match ($status) { 'done', 'sent' => 'go', 'failed' => 'stop', 'skipped', 'not_delivered' => 'caution', default => 'neutral' };
    $mark = fn ($status) => match ($status) { 'done', 'sent' => 'check', 'failed' => 'alert', 'skipped', 'not_delivered' => 'warning', default => 'clock' };
    $active = $incident->status->isActive();
@endphp
<x-passenger-layout :title="$whatWeDid['incident']['kind_label']">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1" tabindex="-1" data-pax-focus>{{ $incident->kind->value === 'sos' ? 'SOS sent' : $whatWeDid['incident']['kind_label'] }}</h1>
            <p class="eh-muted" style="margin:0">Reference {{ $whatWeDid['incident']['reference'] }}, {{ \App\Support\Ui\LocalTime::format($incident->created_at) }} {{ \App\Support\Ui\LocalTime::label() }}</p>
        </div>
    </x-slot:header>

    <div class="pax-page" data-pax-page="incident">
        <section aria-labelledby="inc-calls">
            <h2 id="inc-calls">If you are in danger, call now</h2>
            <ul class="pax-calls">
                @foreach ($whatWeDid['emergency_numbers'] as $number)
                    <li><a href="{{ $number['tel'] }}"><span>{{ $number['label'] }}</span><strong>{{ $number['number'] }}</strong></a></li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="inc-did">
            <h2 id="inc-did">What we did</h2>
            <ul class="pax-did">
                @foreach ($whatWeDid['actions'] as $action)
                    <li>
                        <x-eh.icon :name="$mark($action['status'])" />
                        <span class="pax-did__what">{{ $action['what'] }} <x-eh.badge :tone="$tone($action['status'])">{{ $action['status_text'] ?? 'Done' }}</x-eh.badge></span>
                        @if (! empty($action['why']))<p class="pax-did__why">{{ $action['why'] }}</p>@endif
                    </li>
                @endforeach
            </ul>
            <p class="pax-lead" style="margin-top: var(--eh-space-3)" data-pax-incident-state>{{ ! $active
                ? 'Our operations team resolved this'.($incident->resolved_at ? ' on '.$incident->resolved_at->timezone(config('app.timezone'))->format('j M Y, H:i') : '').'.'
                : ($whatWeDid['incident']['acknowledged'] ? 'Our operations team has seen this.' : 'Our operations team has not acknowledged this yet. If nobody does within minutes, it escalates automatically.') }}</p>
        </section>

        <section aria-labelledby="inc-wa" class="pax-wa">
            <h2 id="inc-wa">Also message someone yourself</h2>
            <p class="pax-lead" style="margin:0">WhatsApp opens with this message ready; you choose who gets it and press send.@if($whatsapp['ride_active']) The message also carries a live link to your trip.@endif</p>
            <blockquote class="pax-wa__text" data-pax-wa-text>{{ $whatsapp['text'] }}</blockquote>
            <div class="pax-actions pax-actions--row" style="margin-top:0">
                <a class="eh-btn eh-btn--primary eh-btn--md" href="https://wa.me/?text={{ rawurlencode($whatsapp['text']) }}" target="_blank" rel="noopener noreferrer" data-pax-wa @if($whatsapp['ride_active'] && $incident->ride_id) data-shares-url="{{ route('safety.rides.shares.store', $incident->ride_id) }}" @endif><x-eh.icon name="chat" /><span class="eh-btn__label">Message on WhatsApp</span></a>
            </div>
        </section>

        <section aria-labelledby="inc-not">
            <h2 id="inc-not">What we did not do</h2>
            @foreach ($whatWeDid['not_done'] as $line)
                <p class="pax-lead">{{ $line }}</p>
            @endforeach
        </section>

        @if ($active)
            <section aria-labelledby="inc-next">
                <h2 id="inc-next">When you are safe</h2>
                <div class="pax-actions pax-actions--row">
                    <x-eh.button icon="check" data-pax-safe data-url="{{ route('safety.incidents.safe', $incident) }}">I am safe now</x-eh.button>
                    <x-eh.button variant="secondary" icon="pin" data-pax-incident-location data-url="{{ route('safety.incidents.location', $incident) }}">Send my location again</x-eh.button>
                    @if ($incident->ride_id)
                        <x-eh.button variant="ghost" :href="route('ride.trip', $incident->ride_id)">Back to the ride</x-eh.button>
                    @endif
                </div>
                <p class="pax-field__status" data-pax-incident-action role="status"></p>
                <p class="pax-rule">Saying you are safe tells our team; they still review what happened.</p>
            </section>
        @endif
    </div>
    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <div id="pax-live-assertive" class="pax-live" aria-live="assertive"></div>
</x-passenger-layout>
