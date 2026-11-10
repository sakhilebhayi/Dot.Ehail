{{--
    Guest shell (sign in, password, verification, legal pages). On wide screens a sign-green panel carries the three
    promises as stops on a route line; on phones the form comes first. Props: title, wide (legal text pages).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh" data-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-guest">
        <aside class="eh-guest__aside" aria-label="Dot.Ehail">
            <x-eh.logo href="{{ url('/') }}" size="40" />
            <ol class="eh-guest__line">
                <li><span class="eh-guest__stop" aria-hidden="true"></span><span>Your journey.<small>Every part of the fare is on screen before you request.</small></span></li>
                <li><span class="eh-guest__stop" aria-hidden="true"></span><span>Your safety.<small>Trip PIN, live trip sharing and SOS on every ride.</small></span></li>
                <li><span class="eh-guest__stop" aria-hidden="true"></span><span>Your choice.<small>Ride, drive, or run a fleet from one account.</small></span></li>
            </ol>
            <p style="margin:0">In an emergency call <a href="tel:10111" style="color:inherit;font-weight:700">10111</a> or <a href="tel:112" style="color:inherit;font-weight:700">112</a> from a mobile.</p>
        </aside>
        <main id="main" class="eh-guest__main" tabindex="-1">
            <div class="eh-guest__top" @if($wide) style="max-width: 48rem" @endif>
                <x-eh.logo href="{{ url('/') }}" />
                <a class="eh-link" href="{{ url('/') }}" style="font-weight:600">Home</a>
            </div>
            <div class="eh-guest__card" @if($wide) style="max-width: 48rem" @endif>
                {{ $slot }}
            </div>
        </main>
    </div>
    <x-eh.toasts />
</body>
</html>
