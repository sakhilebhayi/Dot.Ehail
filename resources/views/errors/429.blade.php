@extends('errors._ecosystem-layout', ['code' => 429])

@section('title', "Slow down a little")
@section('message', "That was a lot of requests in a short time. Wait a minute and try again.")

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
@endsection
