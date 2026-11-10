<x-app-layout>
    <x-slot name="header">
        <h1 class="eh-h1">
            {{ __('API Tokens') }}
        </h1>
    </x-slot>

    <div>
        <div class="eh-js-page">
            @livewire('api.api-token-manager')
        </div>
    </div>
</x-app-layout>
