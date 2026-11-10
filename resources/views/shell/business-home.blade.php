<x-business-layout title="Business">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Business account</h1>
        </div>
    </x-slot:header>

    <x-eh.empty title="Business accounts open here" :pending="true">
        <p style="margin:0">Company travel with ride policies, people you invite, trips billed to the business and monthly invoices. Business accounts are being built now; nothing is set up for you yet.</p>
        <x-slot:actions>
            @if (Route::has('ride.home'))
                <x-eh.button variant="secondary" :href="route('ride.home')" icon="car">Back to riding</x-eh.button>
            @endif
        </x-slot:actions>
    </x-eh.empty>
</x-business-layout>
