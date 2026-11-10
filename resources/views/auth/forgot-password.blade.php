<x-guest-layout title="Reset your password">
    <header>
        <h1 class="eh-guest__title">Reset your password</h1>
        <p class="eh-guest__lead">Enter your email and we will send you a link to choose a new password.</p>
    </header>

    @session('status')
        <x-eh.banner tone="go">{{ $value }}</x-eh.banner>
    @endsession

    <form method="POST" action="{{ route('password.email') }}" class="eh-form" novalidate>
        @csrf
        <x-eh.field label="Email" name="email" type="email" id="email" required autofocus autocomplete="username" inputmode="email" />
        <x-eh.button type="submit" size="lg" block>Email me a reset link</x-eh.button>
    </form>
    <p class="eh-small" style="margin:0"><a class="eh-link" href="{{ route('login') }}">Back to sign in</a></p>
</x-guest-layout>
