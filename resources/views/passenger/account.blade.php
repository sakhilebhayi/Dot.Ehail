{{-- Account: payment methods, receipts, saved places, and links to the pages other parts of Dot.Ehail own. --}}
<x-passenger-layout title="Account">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1">Account</h1><p class="eh-muted" style="margin:0">{{ auth()->user()->name }}, {{ auth()->user()->email }}</p></div>
    </x-slot:header>

    <div class="pax-page" data-pax-page="account">
        <section aria-labelledby="acc-pay">
            <h2 id="acc-pay">Payment methods</h2>
            <p class="pax-field__status" data-pax-wallet-notice></p>
            <ul class="eh-list" data-pax-methods aria-live="polite" aria-busy="true"><li><x-eh.skeleton :lines="2" label="Loading payment methods" /></li></ul>
            <div class="pax-actions pax-actions--row" data-pax-wallet-actions hidden>
                <x-eh.button icon="plus" data-pax-add-card>Add a card</x-eh.button>
            </div>
            <p class="pax-rule" data-pax-card-note></p>
        </section>

        <section aria-labelledby="acc-places">
            <h2 id="acc-places">Saved places</h2>
            <p class="pax-lead">Saved places appear as one-tap choices when you book. They are kept only on your account and you can remove them any time.</p>
            <ul class="eh-list" data-pax-places-list>
                @forelse ($places as $place)
                    <li class="eh-row">
                        <span class="eh-row__icon"><x-eh.icon :name="$place->kind === 'home' ? 'home' : ($place->kind === 'work' ? 'briefcase' : 'pin')" /></span>
                        <span class="eh-row__main"><span class="eh-row__title">{{ $place->label }}</span><span class="eh-row__meta">{{ $place->address }}</span></span>
                        <span class="eh-row__trail"><x-eh.icon-button icon="trash" :label="'Remove '.$place->label" data-pax-place-remove="{{ $place->id }}" /></span>
                    </li>
                @empty
                    <li class="eh-row"><span class="eh-row__main"><span class="eh-row__meta">No saved places yet. Add home or work below.</span></span></li>
                @endforelse
            </ul>
            <form class="eh-panel pax-form pax-section" data-pax-place-form>
                <h3 class="pax-h3" style="margin:0">Save a place</h3>
                <x-eh.segmented legend="Kind of place" name="kind" :options="['home' => ['Home', 'home'], 'work' => ['Work', 'briefcase'], 'other' => ['Other', 'pin']]" value="home" id-prefix="place-kind" />
                <div class="eh-field" data-pax-place-label hidden>
                    <label class="eh-field__label" for="place-label">Name</label>
                    <input class="eh-input" id="place-label" name="label" maxlength="60" placeholder="Gym, school, mum's place">
                </div>
                <div class="pax-field" data-pax-search="place">
                    <label class="pax-field__label" for="place-search">Address</label>
                    <div class="pax-field__row">
                        <input id="place-search" class="eh-input" type="search" placeholder="Street and suburb" enterkeyhint="search">
                        <x-eh.button variant="secondary" data-geo-search-submit icon="search">Search</x-eh.button>
                    </div>
                    <p class="pax-field__hint" data-geo-search-hint></p>
                    <ul class="pax-results" data-geo-search-list></ul>
                    <p class="pax-field__status" data-geo-search-status></p>
                </div>
                <p class="pax-field__status" data-pax-place-status role="status"></p>
                <div><x-eh.button type="submit" icon="plus" data-pax-place-save disabled>Save place</x-eh.button></div>
            </form>
        </section>

        <section aria-labelledby="acc-receipts">
            <h2 id="acc-receipts">Receipts</h2>
            <ul class="eh-list" data-pax-receipts aria-live="polite" aria-busy="true"><li><x-eh.skeleton :lines="2" label="Loading receipts" /></li></ul>
        </section>

        <section aria-labelledby="acc-more">
            <h2 id="acc-more">Your account</h2>
            <ul class="eh-list">
                @if (Route::has('profile.show'))
                    <li><a class="eh-row" href="{{ route('profile.show') }}"><span class="eh-row__icon"><x-eh.icon name="account" /></span><span class="eh-row__main"><span class="eh-row__title">Profile and password</span><span class="eh-row__meta">Name, email, password, two-step sign-in, signed-in devices</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
                @endif
                @if ($notificationsRoute)
                    <li><a class="eh-row" href="{{ route($notificationsRoute) }}"><span class="eh-row__icon"><x-eh.icon name="bell" /></span><span class="eh-row__main"><span class="eh-row__title">Notifications</span><span class="eh-row__meta">What we send you, and how</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
                @endif
                @if ($dataExportRoute)
                    <li><a class="eh-row" href="{{ route($dataExportRoute) }}"><span class="eh-row__icon"><x-eh.icon name="download" /></span><span class="eh-row__main"><span class="eh-row__title">Download my data</span><span class="eh-row__meta">A copy of what Dot.Ehail holds about you</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
                @endif
                <li><a class="eh-row" href="{{ route('ride.safety') }}"><span class="eh-row__icon"><x-eh.icon name="shield" /></span><span class="eh-row__main"><span class="eh-row__title">Safety and trusted contacts</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
                <li><a class="eh-row" href="{{ route('ride.support') }}"><span class="eh-row__icon"><x-eh.icon name="lifebuoy" /></span><span class="eh-row__main"><span class="eh-row__title">Help cases</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
                <li><a class="eh-row" href="{{ route('fares.calculator') }}"><span class="eh-row__icon"><x-eh.icon name="percent" /></span><span class="eh-row__main"><span class="eh-row__title">How fares are worked out</span></span><span class="eh-row__trail"><x-eh.icon name="chevron-right" /></span></a></li>
            </ul>
        </section>
    </div>

    <template id="pax-icons">
        @foreach (['card', 'cash', 'briefcase', 'trash', 'check', 'receipt', 'chevron-right', 'star', 'home', 'pin', 'refresh'] as $iconName)
            <span data-icon="{{ $iconName }}"><x-eh.icon :name="$iconName" /></span>
        @endforeach
    </template>
    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <script type="application/json" data-geo-config>@json(app(\App\Domain\Geo\Support\GeoClientConfig::class)->toArray())</script>
    @php $paxIsland = [
        'urls' => [
            'wallet' => route('money.wallet'),
            'addCard' => route('money.cards.start'),
            'methodDefault' => route('money.methods.default', ['method' => '__ID__']),
            'methodDestroy' => route('money.methods.destroy', ['method' => '__ID__']),
            'receipts' => route('money.receipts.index'),
            'places' => route('ride.api.places.store'),
            'placeDestroy' => route('ride.api.places.destroy', ['place' => '__ID__']),
        ],
    ]; @endphp
    <script type="application/json" id="pax-page-data">@json($paxIsland)</script>
</x-passenger-layout>
