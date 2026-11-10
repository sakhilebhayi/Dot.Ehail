@extends('errors._ecosystem-layout', ['code' => 500])

@section('title', "Something went wrong on our end")
@section('message', "That is on us, not you. An unexpected error stopped this page. Try again in a moment; if it keeps happening, let us know.")

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
    @if (Route::has('contact'))
    <x-eh.button variant="secondary" :href="route('contact')">Contact support</x-eh.button>
    @endif
@endsection
