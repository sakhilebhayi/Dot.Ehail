{{--
    Fleet portal page: the fleet shell, the fleet stylesheet, the title row (h1, the finding under it, actions),
    the fleet switcher when the person works on several fleets, and validation refusals.
--}}
@props(['fleet' => null, 'fleets' => null, 'title', 'line' => null, 'heading' => null])
<x-fleet-layout :title="$title" :scope="$fleet?->name">
    <x-slot:head>@vite(['resources/css/fleet/fleet.css'])</x-slot:head>
    <x-slot:header>
        <div class="fl fl-head" style="width:100%">
            <div class="fl-head__text">
                <h1 class="eh-h1">{{ $heading ?? $title }}</h1>
                @if ($line)<p class="fl-head__line">{{ $line }}</p>@endif
            </div>
            <div class="fl-head__actions">
                @if ($fleet && $fleets && $fleets->count() > 1 && $fleets->contains("id", $fleet->id))
                    <form method="GET" class="fl-switch" action="{{ url()->current() }}">
                        <label for="fl-switch">Fleet</label>
                        <div class="eh-select-wrap">
                            <select id="fl-switch" name="fleet" class="eh-select" onchange="this.form.submit()">
                                @foreach ($fleets as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $fleet->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            <x-eh.icon name="chevron-down" />
                        </div>
                        <noscript><x-eh.button type="submit" variant="secondary" size="sm">Switch</x-eh.button></noscript>
                    </form>
                @endif
                {{ $actions ?? '' }}
            </div>
        </div>
    </x-slot:header>

    <div class="fl fl-stack">
        @if ($errors->has('fleet'))
            <x-eh.banner tone="stop" :title="$errors->first('fleet')" />
        @endif
        {{ $slot }}
    </div>
</x-fleet-layout>
