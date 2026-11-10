Hello {{ $contactName }},

{{ $personFirstName }} pressed the emergency button in Dot.Ehail at {{ $raisedAt }} (reference {{ $reference }}).

@if($vehicle)Vehicle: {{ $vehicle }}
@endif
@if($locationText)Last known location: {{ $locationText }}
@endif
@if($shareUrl)Follow the trip live (link stops working 30 minutes after the trip): {{ $shareUrl }}
@endif

Dot.Ehail's operations team has been alerted in the app. Dot.Ehail does NOT dispatch police, ambulance or security. If you think {{ $personFirstName }} is in danger, try to reach them and call emergency services:
@foreach($emergencyNumbers as $entry)
- {{ $entry['label'] }}: {{ $entry['number'] }}
@endforeach

@if($optOutUrl)Stop receiving these alerts: {{ $optOutUrl }}
@endif
Dot.Ehail
