{{--
    The header search's results page (R-52): what the shared pill's form submits to. Every row comes from
    App\Domain\Search\HeaderSearch, already limited to what this person may see on this surface; this view only prints it.
--}}
<x-dynamic-component :component="$layout" title="Search">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Search</h1>
            @if ($q !== '' && ! $tooShort)
                <p class="eh-muted" style="margin:0">Results for <strong>{{ $q }}</strong></p>
            @endif
        </div>
    </x-slot:header>

    <div class="eh-search-page">
        @if ($q === '')
            <x-eh.empty title="Type what you are looking for"><p style="margin:0">Use the search box at the top of the page.</p></x-eh.empty>
        @elseif ($tooShort)
            <x-eh.empty title="A little more, please"><p style="margin:0">Type at least two characters.</p></x-eh.empty>
        @elseif ($groups === [])
            <x-eh.empty title="Nothing matches"><p style="margin:0">Nothing matches "{{ $q }}". Try a street, a plate or a ride number.</p></x-eh.empty>
        @else
            @foreach ($groups as $group)
                <section class="eh-search-page__group" aria-labelledby="sg-{{ $group['key'] }}">
                    <h2 class="eh-h2" id="sg-{{ $group['key'] }}">{{ $group['head'] }}</h2>
                    <ul class="eh-search-page__list">
                        @foreach ($group['rows'] as $row)
                            <li>
                                <a class="eh-search-page__row" href="{{ $row['href'] }}">
                                    @if ($row['code'])<span class="eh-search-page__code">{{ $row['code'] }}</span>@endif
                                    <span class="eh-search-page__label">{{ $row['label'] }}</span>
                                    @if ($row['tag'])<span class="eh-search-page__tag">{{ $row['tag'] }}</span>@endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($group['more'])
                        <p class="eh-muted" style="margin:0">Showing the first {{ $limit }}. Add a word to narrow it down.</p>
                    @endif
                </section>
            @endforeach
        @endif
    </div>
</x-dynamic-component>
