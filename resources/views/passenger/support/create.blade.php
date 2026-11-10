{{-- New help case (lost property, fare dispute, payment, anything else), posted to the support API. --}}
<x-passenger-layout title="New help case">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1">New help case</h1></div>
    </x-slot:header>
    <div class="pax-page" data-pax-page="support-create">
        <form class="eh-panel pax-form" data-pax-case-form enctype="multipart/form-data">
            <x-eh.select label="What is it about?" name="category" id="case-category" :value="$selectedCategory" :options="collect($categories)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" />
            <div data-pax-case-ride>
                <x-eh.select label="Which trip?" name="ride_id" id="case-ride" :value="$selectedRide?->id" placeholder="Choose a trip" :options="$rides->mapWithKeys(fn ($r) => [$r->id => ($r->completed_at ?? $r->created_at)?->timezone(config('app.timezone'))->format('j M, H:i').': '.\Illuminate\Support\Str::before((string) $r->pickup_address, ',').' to '.\Illuminate\Support\Str::before((string) $r->dropoff_address, ',')])->all()" hint="Lost property, fare disputes and driver conduct need the trip." />
            </div>
            <x-eh.field label="Subject" name="subject" id="case-subject" />
            <x-eh.field label="What happened?" name="body" type="textarea" id="case-body" hint="For lost property: what the item is, where in the car, and how to reach you. Your contact details are never shown to the driver." />
            <div class="eh-field" data-pax-case-amount hidden>
                <label class="eh-field__label" for="case-amount">What do you think the fare should have been? <span class="eh-field__optional">optional</span></label>
                <input class="eh-input" id="case-amount" name="requested_amount" inputmode="decimal" placeholder="R 0.00">
            </div>
            <div class="eh-field">
                <label class="eh-field__label" for="case-file">Photo or document <span class="eh-field__optional">optional</span></label>
                <input id="case-file" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <p class="pax-field__status" data-kind="error" data-pax-case-status role="alert"></p>
            <div class="pax-actions pax-actions--row">
                <x-eh.button type="submit">Open case</x-eh.button>
                <x-eh.button variant="ghost" :href="route('ride.support')">Cancel</x-eh.button>
            </div>
        </form>
    </div>
    @php $paxIsland = [
        'needsRide' => collect($categories)->filter->needsRide()->map->value->values(),
        'urls' => ['store' => route('support.cases.store'), 'case' => route('ride.support.show', ['case' => '__ID__'])],
    ]; @endphp
    <script type="application/json" id="pax-page-data">@json($paxIsland)</script>
</x-passenger-layout>
