@php
    $sev = $incident->severity instanceof \BackedEnum ? $incident->severity->value : (string) $incident->severity;
    $st = $incident->status instanceof \BackedEnum ? $incident->status->value : (string) $incident->status;
@endphp
<x-eh.badge :tone="match($sev){'critical'=>'stop','high'=>'stop','medium'=>'caution',default=>'neutral'}" :icon="$sev === 'critical' ? 'siren' : null">{{ ucfirst($sev) }}</x-eh.badge>
<x-eh.badge :tone="match($st){'open'=>'stop','escalated'=>'stop','acknowledged'=>'caution','resolved'=>'go',default=>'neutral'}">{{ $st === 'open' ? 'Not acknowledged' : ucfirst($st) }}</x-eh.badge>
