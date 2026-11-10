<x-guest-layout title="Choose a new password">
    <header>
        <h1 class="eh-guest__title">Choose a new password</h1>
    </header>

    <form method="POST" action="{{ route('password.update') }}" class="eh-form" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-eh.field label="Email" name="email" type="email" id="email" :value="$request->email" required autofocus autocomplete="username" />
        <x-eh.field label="New password" name="password" type="password" id="password" required autocomplete="new-password" hint="At least 8 characters." />
        <x-eh.field label="Confirm new password" name="password_confirmation" type="password" id="password_confirmation" required autocomplete="new-password" />
        <x-eh.button type="submit" size="lg" block>Save new password</x-eh.button>
    </form>
</x-guest-layout>
