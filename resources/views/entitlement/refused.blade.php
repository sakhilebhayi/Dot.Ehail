{{-- R-50: the company does not hold Dot.Ehail. Built on the ecosystem error layout (Route Line: the route line, the
     display title, one action marked data-primary-action). Rendered by App\Support\EntitlementRefusal with HTTP 403.
     It shows nothing from trips, wallet or account. Switching company and signing out are quiet text controls. --}}
@extends('errors._ecosystem-layout', ['code' => 403])

@section('title', $title)
@section('message', $body)

@section('bar')
    <header class="eh-error__bar">
        <x-eh.logo href="{{ url('/') }}" />
        @auth
            <form method="POST" action="{{ route('logout') }}" class="eh-gate__out">
                @csrf
                <button type="submit" class="eh-link">Sign out</button>
            </form>
        @endauth
    </header>
@endsection

@section('actions')
    <x-eh.button :href="$platformsUrl" data-primary-action>Open Platforms in InfoDot</x-eh.button>
@endsection

@section('after')
    @auth
        @if ($otherCompanies->isNotEmpty())
            <section class="eh-gate__others" aria-labelledby="eh-gate-others">
                <h2 class="eh-gate__h2" id="eh-gate-others">Your other {{ $otherCompanies->count() === 1 ? 'company' : 'companies' }} here</h2>
                <ul class="eh-gate__list">
                    @foreach ($otherCompanies as $other)
                        <li>
                            <b>{{ $other->name }}</b>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $other->id }}">
                                <button type="submit" class="eh-link" aria-label="Switch to {{ $other->name }}">Switch</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endauth
@endsection
