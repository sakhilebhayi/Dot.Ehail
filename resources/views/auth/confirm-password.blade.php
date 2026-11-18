<x-guest-layout title="Confirm your password">
    <header>
        <h1 class="eh-guest__title">Confirm your password</h1>
        <p class="eh-guest__lead">This is a secure area. Enter your password to continue.</p>
    </header>

    <form method="POST" action="{{ route('password.confirm') }}" class="eh-form" novalidate>
        @csrf
        <x-eh.field label="Password" name="password" type="password" id="password" required autocomplete="current-password" autofocus />
        <x-eh.button type="submit" size="lg" block>Confirm</x-eh.button>
    </form>

    @if (config('dotsso.enabled'))
        {{-- R-54: an account that signs in through InfoDot has no password of its own. The gate also accepts a fresh sign-in. --}}
        <form method="POST" action="{{ route('sign-in-again') }}" class="eh-form">
            @csrf
            <p class="eh-small" style="margin:0">Signed in with InfoDot and no Dot.Ehail password? Sign in again instead.</p>
            <x-eh.button type="submit" variant="secondary" size="lg" block>Sign in again with InfoDot</x-eh.button>
        </form>
    @endif
</x-guest-layout>
