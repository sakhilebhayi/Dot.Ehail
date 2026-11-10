Hello {{ $contactName }},

{{ $personFirstName }} is sharing a trip with you on Dot.Ehail.

Follow it here: {{ $shareUrl }}

The page shows the trip's progress, the driver's first name and the vehicle. It stops working at {{ $expiresAt }} or 30 minutes after the trip ends, whichever comes first.

@if($optOutUrl)Stop receiving messages from Dot.Ehail about {{ $personFirstName }}: {{ $optOutUrl }}
@endif
Dot.Ehail
