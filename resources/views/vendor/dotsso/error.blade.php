{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-guest-layout :title="$headline">
    <header>
        <h1 class="eh-guest__title">{{ $headline }}</h1>
        <p class="eh-guest__lead">Signing in to Dot.Ehail through InfoDot did not finish.</p>
    </header>

    <x-eh.banner tone="stop">{{ $body }}</x-eh.banner>

    <div class="eh-claim__row">
        @if ($retry)<x-eh.button :href="$retryUrl" size="lg">Start again</x-eh.button>@endif
        <x-eh.button :href="$homeUrl" variant="secondary" size="lg">Back to Dot.Ehail</x-eh.button>
    </div>
    <p class="eh-small" style="margin:0">Reference: {{ $code }}</p>
</x-guest-layout>
