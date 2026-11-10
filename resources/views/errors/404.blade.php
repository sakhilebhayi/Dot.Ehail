@extends('errors._ecosystem-layout', ['code' => 404])

@section('title', "We couldn't find that page")
@section('message', "The page may have moved, been renamed, or never existed. Check the link, or head somewhere we know works.")

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
    @if (Route::has('fares.calculator'))
    <x-eh.button variant="secondary" :href="route('fares.calculator')" icon="receipt">Check a fare</x-eh.button>
    @endif
@endsection
