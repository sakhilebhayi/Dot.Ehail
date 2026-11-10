<x-guest-layout title="Two-step sign in">
    <header>
        <h1 class="eh-guest__title">Two-step sign in</h1>
        <p class="eh-guest__lead">Enter the six-digit code from your authenticator app.</p>
    </header>

    <x-validation-errors />

    <form method="POST" action="{{ route('two-factor.login') }}" class="eh-form" novalidate>
        @csrf
        <x-eh.field label="Authentication code" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" autofocus />
        <details class="eh-panel eh-panel--quiet">
            <summary style="cursor:pointer;font-weight:600;min-height:var(--eh-target);display:flex;align-items:center">Use a recovery code instead</summary>
            <div style="margin-top:var(--eh-space-3)">
                <x-eh.field label="Recovery code" name="recovery_code" id="recovery_code" autocomplete="one-time-code" hint="One of the codes you saved when you turned on two-step sign in." />
            </div>
        </details>
        <x-eh.button type="submit" size="lg" block>Sign in</x-eh.button>
    </form>
</x-guest-layout>
