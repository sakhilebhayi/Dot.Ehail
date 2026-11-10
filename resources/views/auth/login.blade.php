<x-guest-layout title="Sign in">
    <header>
        <h1 class="eh-guest__title">Sign in</h1>
        <p class="eh-guest__lead">Use your Dot account. If you signed up through InfoDot, the same email and password work here.</p>
    </header>

    @session('status')
        <x-eh.banner tone="go">{{ $value }}</x-eh.banner>
    @endsession

    <form method="POST" action="{{ route('login') }}" class="eh-form" novalidate>
        @csrf
        <x-eh.field label="Email" name="email" type="email" id="email" required autofocus autocomplete="username" inputmode="email" />
        <x-eh.field label="Password" name="password" type="password" id="password" required autocomplete="current-password" />
        <div class="eh-form__row">
            <label class="eh-check" for="remember_me"><input type="checkbox" id="remember_me" name="remember"> Keep me signed in</label>
            @if (Route::has('password.request'))
                <a class="eh-link" href="{{ route('password.request') }}">Forgot your password?</a>
            @endif
        </div>
        <x-eh.button type="submit" size="lg" block>Sign in</x-eh.button>
    </form>

    @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
        <p class="eh-small" style="margin:0">New here? <a class="eh-link" href="{{ route('register') }}">Create an account</a></p>
    @endif
</x-guest-layout>
