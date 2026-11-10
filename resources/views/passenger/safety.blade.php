{{--
    Safety centre: emergency numbers first, the ride going now (SOS and sharing), trusted contacts, reports and
    the person's own incidents. Every statement about what happens comes from docs/R46_SAFETY.md.
--}}
<x-passenger-layout title="Safety">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    @if ($activeRide)
        <x-slot:safety>
            <button type="button" class="eh-safety-btn eh-safety-btn--sos" data-pax-sos-open><x-eh.icon name="sos" />SOS</button>
        </x-slot:safety>
    @endif
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1">Safety</h1></div>
    </x-slot:header>

    <div class="pax-page" data-pax-page="safety">
        <section aria-labelledby="safety-calls">
            <h2 id="safety-calls">In danger? Call now</h2>
            <ul class="pax-calls">
                @foreach ($emergencyNumbers as $number)
                    <li><a href="{{ $number['tel'] }}"><span>{{ $number['label'] }}</span><strong>{{ $number['number'] }}</strong></a></li>
                @endforeach
            </ul>
            <p class="pax-lead" style="margin-top: var(--eh-space-3)">These reach South African emergency services directly. Dot.Ehail does not dispatch emergency services: the SOS button on a ride alerts our operations team and your confirmed trusted contacts, and shows you exactly what was sent.</p>
        </section>

        @if ($activeRide)
            <section aria-labelledby="safety-now">
                <h2 id="safety-now">Your ride now</h2>
                <div class="eh-panel">
                    <p style="margin:0 0 var(--eh-space-3)"><x-eh.status :status="$activeRide->status" /> {{ \Illuminate\Support\Str::before((string) $activeRide->pickup_address, ',') }} to {{ \Illuminate\Support\Str::before((string) $activeRide->dropoff_address, ',') }}</p>
                    <div class="pax-actions pax-actions--row">
                        <x-eh.button variant="danger" icon="sos" data-pax-sos-open>SOS</x-eh.button>
                        <x-eh.button variant="secondary" icon="share" data-pax-share-open>Share this trip</x-eh.button>
                        <x-eh.button variant="ghost" :href="route('ride.trip', $activeRide)">Open the ride</x-eh.button>
                    </div>
                    @include('passenger.partials.share-box', ['heading' => 'h3'])
                </div>
            </section>
        @endif

        <section aria-labelledby="safety-contacts">
            <h2 id="safety-contacts">Trusted contacts</h2>
            <p class="pax-lead">Up to five people we {{ $smsConfigured ? 'email and text' : 'email' }} if you press SOS or ask for help during a check-in. Each one confirms by email first; until then they get nothing.</p>
            <ul class="eh-list" data-pax-contacts aria-live="polite" aria-busy="true"><li><x-eh.skeleton :lines="2" label="Loading your contacts" /></li></ul>
            <form class="eh-panel pax-form pax-section" data-pax-contact-form>
                <h3 class="pax-h3" style="margin:0">Add a contact</h3>
                <x-eh.field label="Name" name="name" id="contact-name" />
                <x-eh.field label="Email" name="email" type="email" id="contact-email" />
                <x-eh.field label="Mobile number" name="phone" type="tel" id="contact-phone" :optional="true" :hint="$smsConfigured ? 'We text this number too if you press SOS.' : 'SMS alerts are off: the operator has not set up an SMS provider. We email your contacts.'" />
                <x-eh.field label="Relationship" name="relationship" id="contact-relationship" :optional="true" />
                <p class="pax-field__status" data-pax-contact-status role="status"></p>
                <div><x-eh.button type="submit" icon="plus">Add contact</x-eh.button></div>
            </form>
        </section>

        <section aria-labelledby="safety-checkins" data-pax-checkin-list hidden>
            <h2 id="safety-checkins">Waiting for your answer</h2>
            <ul class="eh-list" data-pax-checkins></ul>
        </section>

        <section aria-labelledby="safety-report">
            <h2 id="safety-report">Report a safety issue</h2>
            <p class="pax-lead">For something that happened on a trip or around one. Our safety team reviews every report; the other person is not told who reported.</p>
            <form class="eh-panel pax-form" data-pax-incident-form>
                <x-eh.select label="What happened?" name="kind" id="incident-kind" :options="['harassment' => 'Harassment', 'accident' => 'Accident', 'other' => 'Another safety concern']" />
                <x-eh.select label="Which trip?" name="ride_id" id="incident-ride" placeholder="Not about a specific trip" :options="$recentRides->mapWithKeys(fn ($r) => [$r->id => ($r->completed_at ?? $r->created_at)?->timezone(config('app.timezone'))->format('j M, H:i').': '.\Illuminate\Support\Str::before((string) $r->dropoff_address, ',')])->all()" />
                <x-eh.field label="What should we know?" name="description" type="textarea" id="incident-description" />
                <p class="pax-field__status" data-pax-incident-status role="status"></p>
                <div><x-eh.button type="submit" variant="secondary">Send report</x-eh.button></div>
            </form>
        </section>

        <section aria-labelledby="safety-history">
            <h2 id="safety-history">Your safety reports</h2>
            @if ($incidents->isEmpty())
                <p class="pax-lead">None. If you ever press SOS or report something, it appears here with what we did.</p>
            @else
                <ul class="eh-list">
                    @foreach ($incidents as $incident)
                        <li>
                            <a class="eh-row" href="{{ route('ride.safety.incident', $incident) }}">
                                <span class="eh-row__main">
                                    <span class="eh-row__title">{{ $incident->kind->label() }} <span class="eh-muted">{{ $incident->reference }}</span></span>
                                    <span class="eh-row__meta">{{ $incident->created_at->timezone(config('app.timezone'))->format('j M Y, H:i') }}, {{ ucfirst(str_replace('_', ' ', $incident->status->value)) }}</span>
                                </span>
                                <span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if ($activeRide)
        @include('passenger.partials.sos', ['rideId' => $activeRide->id])
    @endif
    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <div id="pax-live-assertive" class="pax-live" aria-live="assertive"></div>
    @php $paxIsland = [
        'urls' => [
            'contacts' => route('safety.contacts.index'),
            'contactsStore' => route('safety.contacts.store'),
            'contactDestroy' => route('safety.contacts.destroy', ['contact' => '__ID__']),
            'contactResend' => route('safety.contacts.resend', ['contact' => '__ID__']),
            'incidents' => route('safety.incidents.store'),
            'incidentPage' => route('ride.safety.incident', ['incident' => '__ID__']),
            'checkIns' => route('safety.check-ins.index'),
            'shares' => $activeRide ? route('safety.rides.shares.store', $activeRide) : null,
            'shareRevoke' => route('safety.shares.revoke', ['share' => '__ID__']),
        ],
    ]; @endphp
    <script type="application/json" id="pax-page-data">@json($paxIsland)</script>
</x-passenger-layout>
