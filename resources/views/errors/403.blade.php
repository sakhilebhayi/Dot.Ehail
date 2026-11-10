@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', "You don't have access to this")
@section('message', "This page belongs to another account or role. If you think you should see it, ask the person who manages your team or fleet.")

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
