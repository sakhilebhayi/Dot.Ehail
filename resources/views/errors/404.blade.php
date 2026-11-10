@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page may have moved, been renamed, or never existed. Check the link, or head somewhere we know works.")

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
    @auth
    <x-eh.button variant="secondary" :href="Route::has('home') ? route('home') : route('dashboard')">Open the app</x-eh.button>
    @else
        @if (Route::has('login'))
    <x-eh.button variant="secondary" :href="route('login')">Sign in</x-eh.button>
        @endif
    @endauth
@endsection
