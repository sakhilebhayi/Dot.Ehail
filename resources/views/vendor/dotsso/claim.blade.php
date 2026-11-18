{{-- R-54: the claim page, in the Route Line guest shell. Shown when a Dot.Ehail account already uses the email address of the InfoDot
     sign-in. Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-guest-layout title="Confirm it is you">
    <header>
        <h1 class="eh-guest__title">Confirm it is you</h1>
        <p class="eh-guest__lead">A Dot.Ehail account already uses {{ $maskedEmail }}. We sent a {{ $codeLength }}-digit code to that address. Your trips, driver and fleet records stay exactly as they are.</p>
    </header>

    @if (session('dotsso_status'))
        <x-eh.banner tone="info">{{ session('dotsso_status') }}</x-eh.banner>
    @endif

    <form method="POST" action="{{ $verifyUrl }}" class="eh-form eh-claim" novalidate>
        @csrf
        <div aria-live="polite" class="eh-claim__code">
            <x-eh.field label="Code from the email" name="code" id="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                maxlength="{{ $codeLength }}" required autofocus
                hint="It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries." />
        </div>
        <x-eh.button type="submit" size="lg" block>Confirm and continue</x-eh.button>
    </form>

    <div class="eh-claim__row">
        <form method="POST" action="{{ $resendUrl }}">@csrf<x-eh.button type="submit" variant="secondary">Send a new code</x-eh.button></form>
        <form method="POST" action="{{ $cancelUrl }}">@csrf<x-eh.button type="submit" variant="secondary">Cancel</x-eh.button></form>
    </div>
</x-guest-layout>
