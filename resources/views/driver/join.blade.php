{{-- /drive for someone who is not registered to drive: what it takes, and the one way in. --}}
<x-driver-layout title="Drive">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:state>
        <section class="eh-statebar" data-state="offline" aria-label="Driver status">
            <x-eh.icon name="offline" class="eh-statebar__mark" />
            <div class="eh-statebar__text">
                <h1 class="eh-statebar__title" id="drive-state-title">Not registered to drive</h1>
                <p class="eh-statebar__sub">Apply once. A person reviews your documents before your first trip.</p>
            </div>
        </section>
    </x-slot:state>

    <div class="dr-page">
        <section class="dr-section" aria-labelledby="join-h">
            <h2 id="join-h">What you need</h2>
            <ol class="dr-steps">
                <li data-state="current"><span class="dr-steps__dot" aria-hidden="true"></span><span class="dr-steps__title">Your application<span class="dr-steps__meta">Licence and ID numbers; join a fleet or drive independently.</span></span><span></span></li>
                <li data-state="todo"><span class="dr-steps__dot" aria-hidden="true"></span><span class="dr-steps__title">Your vehicle<span class="dr-steps__meta">Make, model, year, colour, number plate and class.</span></span><span></span></li>
                <li data-state="todo"><span class="dr-steps__dot" aria-hidden="true"></span><span class="dr-steps__title">Documents<span class="dr-steps__meta">ID, driver's licence, PrDP, profile photo, licence disc, roadworthy, insurance, operating licence and vehicle photos.</span></span><span></span></li>
                <li data-state="todo"><span class="dr-steps__dot" aria-hidden="true"></span><span class="dr-steps__title">Review by a person<span class="dr-steps__meta">Then you can go online and take offers.</span></span><span></span></li>
            </ol>
            <p class="dr-note">Every offer shows the pickup distance, the trip and what you earn after commission before you accept.</p>
        </section>
        @if (Route::has('drive.apply'))
            <x-eh.button size="driver" :href="route('drive.apply')" icon="steering-wheel" block>Apply to drive</x-eh.button>
        @endif
    </div>
</x-driver-layout>
