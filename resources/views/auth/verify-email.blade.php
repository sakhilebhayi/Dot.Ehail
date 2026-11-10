<x-guest-layout title="Verify your email">
    <header>
        <h1 class="eh-guest__title">Check your email</h1>
        <p class="eh-guest__lead">We sent a link to confirm your email address. Open it to continue. If it has not arrived, we can send another.</p>
    </header>

    @if (session('status') == 'verification-link-sent')
        <x-eh.banner tone="go">A new verification link has been sent to the email address in your profile.</x-eh.banner>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-eh.button type="submit" size="lg" block>Send the link again</x-eh.button>
    </form>
    <div class="eh-form__row">
        <a class="eh-link" href="{{ route('profile.show') }}">Edit profile</a>
        <form method="POST" action="{{ route('logout') }}" style="margin:0">
            @csrf
            <x-eh.button type="submit" variant="ghost" size="sm">Sign out</x-eh.button>
        </form>
    </div>
</x-guest-layout>
