{{-- Account menu: who is signed in, other surfaces they may switch to, profile, appearance, sign out. --}}
@php
    $accountUser = auth()->user();
    $accountSwitches = app(\App\Support\Ui\Navigation::class)->switches($surface, $accountUser);
@endphp
@if ($accountUser)
<details class="eh-account" data-eh-menu>
    <summary aria-label="Account menu for {{ $accountUser->name }}"><x-eh.avatar :name="$accountUser->name" size="36" :mark="true" /></summary>
    <div class="eh-account__menu">
        <div class="eh-account__who">
            <span class="eh-account__name">{{ $accountUser->name }}</span>
            <span class="eh-account__email">{{ $accountUser->email }}</span>
        </div>
        @if (count($accountSwitches))
            <div class="eh-account__group">
                <p class="eh-account__label" style="margin:0">Switch to</p>
                @foreach ($accountSwitches as $switch)
                    <a class="eh-account__item" href="{{ $switch['url'] }}"><x-eh.icon :name="['passenger' => 'car', 'driver' => 'steering-wheel', 'ops' => 'activity', 'business' => 'briefcase', 'fleet' => 'fleet'][$switch['surface']]" />{{ $switch['label'] }}</a>
                @endforeach
            </div>
        @endif
        <div class="eh-account__group">
            <a class="eh-account__item" href="{{ route('profile.show') }}"><x-eh.icon name="account" />Profile and security</a>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $accountUser->currentTeam)
                <a class="eh-account__item" href="{{ route('teams.show', $accountUser->currentTeam->id) }}"><x-eh.icon name="users" />Team settings</a>
            @endif
        </div>
        <div class="eh-account__group">
            <p class="eh-account__label" style="margin:0" id="eh-theme-label-{{ $surface }}">Appearance</p>
            <div class="eh-account__theme">
                <x-eh.segmented legend="Appearance" name="eh-theme-{{ $surface }}" :id-prefix="'theme-'.$surface" data-eh-theme-choice
                    :options="$surface === 'driver' ? ['night' => 'Auto', 'light' => 'Light', 'dark' => 'Dark'] : ['system' => 'Auto', 'light' => 'Light', 'dark' => 'Dark']"
                    :value="$surface === 'driver' ? 'night' : 'system'" />
            </div>
        </div>
        <div class="eh-account__group">
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="eh-account__item"><x-eh.icon name="sign-out" />Sign out</button>
            </form>
        </div>
    </div>
</details>
@endif
