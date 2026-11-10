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
</x-guest-layout>
