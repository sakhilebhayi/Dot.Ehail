{{--
    Wraps comms pages in the surface's shell: passenger, driver or ops. $surface picks it; $title is the page title.
    Page styles and script come in through the shell's head slot.
--}}
@php($layout = ['passenger' => 'passenger-layout', 'driver' => 'driver-layout', 'ops' => 'ops-layout'][$surface] ?? 'passenger-layout')
<x-dynamic-component :component="$layout" :title="$title">
    <x-slot:head>
        @vite(['resources/css/comms/comms.css', 'resources/js/comms/comms.js'])
    </x-slot:head>
    <x-slot:header>
        {{ $header }}
    </x-slot:header>
    {{ $slot }}
</x-dynamic-component>
