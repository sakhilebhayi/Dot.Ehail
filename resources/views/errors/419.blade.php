@extends('errors._ecosystem-layout', ['code' => 419])

@section('title', "Your session timed out")
@section('message', "For your security, forms expire after a while without activity. Nothing was lost. Go back and send it again.")

@section('actions')
    <x-eh.button :href="url()->previous() === url()->current() ? url('/') : url()->previous()" icon="arrow-left">Go back and retry</x-eh.button>
    <x-eh.button variant="secondary" :href="url('/')">Go home</x-eh.button>
@endsection
