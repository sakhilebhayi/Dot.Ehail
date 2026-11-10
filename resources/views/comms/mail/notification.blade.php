<x-mail::message>
# {{ $heading }}

{{ $body }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
Open Dot.Ehail
</x-mail::button>
@endif

<small>You get this email for "{{ $categoryLabel }}". Change what we email you in [notification settings]({{ $settingsUrl }}).</small>
</x-mail::message>
