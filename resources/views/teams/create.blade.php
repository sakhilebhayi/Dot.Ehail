<x-app-layout>
    <x-slot name="header">
        <h1 class="eh-h1">
            {{ __('Create Team') }}
        </h1>
    </x-slot>

    <div>
        <div class="eh-js-page">
            @livewire('teams.create-team-form')
        </div>
    </div>
</x-app-layout>
