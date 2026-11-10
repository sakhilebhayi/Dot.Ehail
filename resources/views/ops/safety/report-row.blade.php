<tr>
    <td>{{ str_replace('_', ' ', $r->category) }}@if($r->description)<span class="sub">{{ \Illuminate\Support\Str::limit($r->description, 140) }}</span>@endif @if(is_array($r->evidence) && isset($r->evidence['total']))<span class="sub">{{ $r->evidence['total'] }} cancellations in {{ $r->evidence['window_days'] ?? 7 }} days</span>@endif</td>
    <td>@if($r->reportedUser)@can('ops.drivers.view')<a href="{{ route('ops.drivers.show', $r->reported_user_id) }}">{{ $r->reportedUser->name }}</a>@else{{ $r->reportedUser->name }}@endcan @endif<span class="sub">{{ $r->evidence['subject_role'] ?? '' }}</span></td>
    <td>{{ $r->reporter?->name ?? 'System' }}<span class="sub">{{ $r->reporter_role }}{{ $r->ride_id ? ' · ride '.$r->ride_id : '' }}</span></td>
    <td><x-eh.badge :tone="match($r->status){'open'=>'caution','reviewing'=>'info','actioned'=>'go',default=>'neutral'}">{{ ucfirst($r->status) }}</x-eh.badge>@if($r->outcome_note)<span class="sub">{{ $r->outcome_note }}</span>@endif</td>
    <td>
        @if ($canAct && in_array($r->status, ['open', 'reviewing'], true))
            <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.reports.review', $r->id) }}" data-ops-done="Report updated.">
                <div class="ops-act__row">
                    <label class="eh-sr" for="rs-{{ $r->id }}">Outcome</label>
                    <select id="rs-{{ $r->id }}" class="eh-select" name="status" style="width:auto;padding-right:var(--eh-space-4)"><option value="reviewing">Reviewing</option><option value="actioned">Actioned</option><option value="dismissed">Dismissed</option></select>
                    <label class="eh-sr" for="rn-{{ $r->id }}">Note</label>
                    <input id="rn-{{ $r->id }}" class="eh-input" name="note" maxlength="2000" placeholder="What you did" style="flex:1 1 10rem">
                    <x-eh.button type="submit" size="sm" variant="secondary">Save</x-eh.button>
                </div>
            </form>
        @endif
    </td>
</tr>
