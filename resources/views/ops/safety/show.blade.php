@php
    $st = $incident->status->value;
    $active = in_array($st, ['open', 'acknowledged', 'escalated'], true);
    $ctx = is_array($incident->context) ? $incident->context : [];
    $finding = match ($st) {
        'open' => ['Not acknowledged. '.($incident->next_escalation_at ? ($incident->next_escalation_at->isPast() ? 'Past the escalation time.' : 'Escalates at '.$incident->next_escalation_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i').'.') : ''), 'stop'],
        'escalated' => ['Escalated to level '.$incident->escalation_level.'. Someone must own it.', 'stop'],
        'acknowledged' => ['Acknowledged by '.($incident->owner?->name ?? 'an operator').'. Resolve it when the person is safe.', 'caution'],
        default => [ucfirst($st).($incident->resolution_code ? ': '.str_replace('_', ' ', $incident->resolution_code) : '').'.', null],
    };
@endphp
<x-ops-layout :title="$incident->reference">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.safety') }}">Safety</a> / {{ $incident->reference }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>{{ $incident->kind->label() }} {{ $incident->reference }} @include('ops.partials.incident-badges')</h1>
                <p class="ops-head__finding" @if($finding[1]) data-tone="{{ $finding[1] }}" @endif>{{ $finding[0] }}</p>
            </div>
            @if ($canAct && $st === 'open')
                <form class="ops-head__actions" data-ops-action method="post" action="{{ route('safety.ops.incidents.acknowledge', $incident->id) }}" data-ops-done="Acknowledged. You own this incident.">
                    <x-eh.button type="submit" icon="check">Acknowledge and own</x-eh.button>
                </form>
            @endif
        </header>
        <x-eh.banner tone="safety" title="Dot.Ehail does not dispatch police or an ambulance" :emergency="true" style="margin-bottom:var(--eh-space-4)">If someone is in danger, call. Record every call you make as a note.</x-eh.banner>

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="ctx-h">
                    <div class="ops-panel__head"><h2 id="ctx-h">What was reported</h2><span class="ops-panel__meta">{{ $incident->created_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i:s') }} · source {{ str_replace('_', ' ', (string) $incident->source) }}</span></div>
                    <dl class="ops-facts">
                        <dt>Raised by</dt><dd>{{ $incident->reporter?->name ?? 'System' }} ({{ $incident->reporter_role ?? 'system' }})</dd>
                        @if ($incident->description)<dt>Their words</dt><dd>{{ $incident->description }}</dd>@endif
                        @if ($incident->ride)
                            <dt>Ride</dt><dd>@can('ops.rides.view')<a class="eh-link" href="{{ route('ops.rides.show', $incident->ride_id) }}">Ride {{ $incident->ride_id }}</a>@else Ride {{ $incident->ride_id }} @endcan · <x-eh.status :status="$incident->ride->status" /></dd>
                            <dt>Passenger</dt><dd>{{ $incident->ride->passenger?->name }}</dd>
                            <dt>Driver</dt><dd>{{ $incident->ride->driver?->name ?? 'none assigned' }}</dd>
                            <dt>Route</dt><dd>{{ $incident->ride->pickup_address }} to {{ $incident->ride->dropoff_address }}</dd>
                        @endif
                        <dt>Reporter's position</dt><dd>@if($incident->location_lat !== null){{ number_format((float) $incident->location_lat, 5) }}, {{ number_format((float) $incident->location_lng, 5) }} <span class="ops-stamp">± {{ $incident->location_accuracy_m ?? '?' }} m, {{ $incident->location_captured_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</span>@else<span class="ops-dash">not sent</span>@endif</dd>
                        @if (isset($ctx['vehicle']) && is_array($ctx['vehicle']))<dt>Vehicle</dt><dd>{{ implode(' ', array_filter(array_map('strval', array_intersect_key($ctx['vehicle'], array_flip(['colour', 'color', 'make', 'model', 'registration', 'plate_number']))))) }}</dd>@endif
                        @if (isset($ctx['driver_last_position']) && is_array($ctx['driver_last_position']))<dt>Driver's last position</dt><dd class="num">{{ $ctx['driver_last_position']['lat'] ?? '?' }}, {{ $ctx['driver_last_position']['lng'] ?? '?' }}</dd>@endif
                    </dl>
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="notif-h">
                    <div class="ops-panel__head"><h2 id="notif-h">What we did</h2><span class="ops-panel__meta">every alert with its real outcome</span></div>
                    @if ($incident->notifications->isEmpty())
                        <p class="ops-none">No alert was attempted.</p>
                    @else
                        <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                            <thead><tr><th>Who</th><th>How</th><th>Why</th><th>Outcome</th><th class="num">When</th></tr></thead>
                            <tbody>
                                @foreach ($incident->notifications->sortBy('id') as $n)
                                    <tr>
                                        <td>{{ $n->recipient_label ?? str_replace('_', ' ', (string) $n->recipient_kind) }}</td>
                                        <td>{{ strtoupper((string) $n->channel) === 'SMS' ? 'SMS' : ucfirst(str_replace('_', ' ', (string) $n->channel)) }}</td>
                                        <td>{{ str_replace('_', ' ', (string) $n->purpose) }}</td>
                                        <td><x-eh.badge :tone="match($n->status){'sent'=>'go','failed'=>'stop','not_delivered'=>'caution','skipped'=>'neutral',default=>'info'}">{{ str_replace('_', ' ', (string) $n->status) }}</x-eh.badge>@if($n->error)<span class="sub">{{ $n->error }}</span>@endif</td>
                                        <td class="num">{{ ($n->sent_at ?? $n->attempted_at ?? $n->created_at)?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>

                <section class="ops-panel" aria-labelledby="tl-h">
                    <div class="ops-panel__head"><h2 id="tl-h">Timeline</h2></div>
                    <ol class="ops-route">
                        @foreach ($incident->events->sortBy('id') as $event)
                            @php($data = is_array($event->data) ? $event->data : [])
                            <li data-kind="{{ in_array($event->type, ['escalated', 'opened'], true) ? 'stop' : ($event->type === 'note' ? 'side' : 'main') }}" @if($loop->last) data-current @endif>
                                <span class="ops-route__stop" aria-hidden="true"></span>
                                <span class="ops-route__what">{{ ucfirst(str_replace('_', ' ', (string) $event->type)) }}</span>
                                <time class="ops-route__when">{{ $event->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i:s') }}</time>
                                <span class="ops-route__detail">{{ $event->actor_id ? 'by user '.$event->actor_id : 'system' }}{{ isset($data['note']) && is_string($data['note']) ? ' · "'.$data['note'].'"' : '' }}{{ isset($data['reason']) && is_string($data['reason']) ? ' · '.str_replace('_', ' ', $data['reason']) : '' }}{{ isset($data['level']) ? ' · level '.$data['level'] : '' }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>

                @if ($checkIns->isNotEmpty() || $reports->isNotEmpty())
                    <section class="ops-panel ops-panel--flush" aria-labelledby="related-h">
                        <div class="ops-panel__head"><h2 id="related-h">On the same ride</h2></div>
                        <ul class="ops-queue">
                            @foreach ($checkIns as $c)<li><span class="ops-queue__title">Check-in ({{ str_replace('_', ' ', $c->trigger) }}) to the {{ $c->role }}</span><span class="ops-queue__meta">{{ str_replace('_', ' ', $c->status) }} · prompted {{ $c->prompted_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i') }}</span></li>@endforeach
                            @foreach ($reports as $r)<li><span class="ops-queue__title">Report: {{ str_replace('_', ' ', $r->category) }}</span><span class="ops-queue__meta">by the {{ $r->reporter_role }} · {{ $r->status }}</span></li>@endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <div class="ops-stack">
                @if ($canAct && $active)
                    <section class="ops-panel" aria-labelledby="act-h">
                        <div class="ops-panel__head"><h2 id="act-h">Act</h2><span class="ops-panel__meta">owner {{ $incident->owner?->name ?? 'nobody' }}</span></div>
                        <div class="ops-actions">
                            <details open>
                                <summary>Add a note <span class="ops-hint">calls made, who you spoke to</span></summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.incidents.note', $incident->id) }}" data-ops-done="Note added.">
                                    <x-eh.field label="Note" name="note" type="textarea" required maxlength="2000" id="inc-note" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Add note</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Give it to someone</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.incidents.assign', $incident->id) }}" data-ops-done="Owner changed.">
                                    <x-eh.select label="Owner" name="owner_id" :options="$operators->pluck('name', 'id')->all()" :value="(string) ($incident->owner_id ?? auth()->id())" id="inc-owner" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Assign</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Escalate now</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.incidents.escalate', $incident->id) }}" data-ops-done="Escalated.">
                                    <x-eh.field label="Why" name="reason" type="text" maxlength="200" id="inc-esc" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Escalate</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Resolve</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.incidents.resolve', $incident->id) }}" data-ops-done="Incident resolved.">
                                    <x-eh.select label="Outcome" name="resolution_code" :options="collect($resolutionCodes)->mapWithKeys(fn ($c) => [$c => ucfirst(str_replace('_', ' ', $c))])->all()" id="inc-res" />
                                    <x-eh.field label="Note (needed for Other)" name="note" type="textarea" maxlength="2000" id="inc-res-note" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm">Resolve</x-eh.button></div>
                                </form>
                            </details>
                        </div>
                    </section>
                @elseif ($canAct && $st === 'resolved')
                    <section class="ops-panel" aria-labelledby="close-h">
                        <div class="ops-panel__head"><h2 id="close-h">Close</h2></div>
                        <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.incidents.close', $incident->id) }}" data-ops-done="Incident closed.">
                            <p class="ops-note ops-note--small">Resolved {{ $incident->resolved_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}{{ $incident->resolution_note ? ': '.$incident->resolution_note : '' }}.</p>
                            <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Close the incident</x-eh.button></div>
                        </form>
                    </section>
                @endif

                @if ($viewers !== null)
                    <section class="ops-panel ops-panel--flush" aria-labelledby="viewers-h">
                        <div class="ops-panel__head"><h2 id="viewers-h">Who opened it</h2><span class="ops-panel__meta">from the audit log</span></div>
                        @if ($viewers->isEmpty())
                            <p class="ops-none">No recorded access.</p>
                        @else
                            <table class="ops-table ops-table--compact">
                                <tbody>
                                    @foreach ($viewers as $row)<tr><td>{{ $row->actor?->name ?? 'System' }}<span class="sub">{{ str_replace(['ops.viewed.', '_'], ['viewed ', ' '], $row->action) }}</span></td><td class="num">{{ $row->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</td></tr>@endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-ops-layout>
