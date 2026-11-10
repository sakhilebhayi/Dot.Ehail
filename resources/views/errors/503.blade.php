@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', "We'll be right back")
@section('message', "Dot.Ehail is being updated. It should be back within a few minutes.")

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
@endsection
