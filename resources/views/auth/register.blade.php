<x-guest-layout title="Create an account">
    <header>
        <h1 class="eh-guest__title">Create an account</h1>
        <p class="eh-guest__lead">One account to ride, to drive, or to run a fleet.</p>
    </header>

    <form method="POST" action="{{ route('register') }}" class="eh-form" novalidate>
        @csrf
        <x-eh.field label="Full name" name="name" id="name" required autofocus autocomplete="name" />
        <x-eh.field label="Email" name="email" type="email" id="email" required autocomplete="username" inputmode="email" />
        <x-eh.field label="Password" name="password" type="password" id="password" required autocomplete="new-password" hint="At least 8 characters." />
        <x-eh.field label="Confirm password" name="password_confirmation" type="password" id="password_confirmation" required autocomplete="new-password" />

        @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
            <div class="eh-field">
                <label class="eh-check" for="terms">
                    <input type="checkbox" name="terms" id="terms" required @if($errors->has('terms')) aria-invalid="true" aria-describedby="terms-error" @endif>
                    <span>I agree to the <a class="eh-link" target="_blank" href="{{ route('terms.show') }}">Terms of Service</a> and the <a class="eh-link" target="_blank" href="{{ route('policy.show') }}">Privacy Policy</a></span>
                </label>
                @error('terms')<p class="eh-field__error" id="terms-error"><x-eh.icon name="alert" />{{ $message }}</p>@enderror
            </div>
        @endif

        <x-eh.button type="submit" size="lg" block>Create account</x-eh.button>
    </form>
    <p class="eh-small" style="margin:0">Already registered? <a class="eh-link" href="{{ route('login') }}">Sign in</a></p>
</x-guest-layout>
