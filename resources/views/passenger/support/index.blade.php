{{-- Help cases: the passenger's support cases from the support API, newest first. --}}
<x-passenger-layout title="Help">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1">Help</h1></div>
        <x-eh.button :href="route('ride.support.create')" icon="plus">New help case</x-eh.button>
    </x-slot:header>
    <div class="pax-page" data-pax-page="support-index">
        <section aria-labelledby="help-cases">
            <h2 id="help-cases">Your cases</h2>
            <ul class="eh-list" data-pax-cases aria-live="polite" aria-busy="true"><li><x-eh.skeleton :lines="2" label="Loading your cases" /></li></ul>
        </section>
        <section aria-labelledby="help-urgent">
            <h2 id="help-urgent">Urgent?</h2>
            <p class="pax-lead">During a ride, use SOS in the top bar. If you are in danger, call 10111 (police) or 112 from a mobile.</p>
        </section>
    </div>
    @php $paxIsland = ['urls' => ['cases' => route('support.cases.index'), 'case' => route('ride.support.show', ['case' => '__ID__'])]]; @endphp
    <script type="application/json" id="pax-page-data">@json($paxIsland)</script>
</x-passenger-layout>
