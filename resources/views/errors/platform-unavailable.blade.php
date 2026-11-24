{{-- Shown with a 503 when InfoDot has switched Dot.Ehail off (maintenance or unavailable). It reads nothing of the
     person's trips, wallet or account; everything already here is kept as it is. A trip in progress never gets this page
     (see EnsureCompanyEntitled). --}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('title', 'Dot.Ehail is not available right now')
@section('message', ($message ?? 'Dot.Ehail is temporarily unavailable.').' Everything recorded here is kept as it is.')

@section('actions')
    <x-eh.button :href="url('/')" icon="home">Go home</x-eh.button>
@endsection
