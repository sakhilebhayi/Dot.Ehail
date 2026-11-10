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
        </header>

        @php
            $me = auth()->id();
            $ownedByMe = $incident->owner_id !== null && (int) $incident->owner_id === (int) $me;
            $unowned = $incident->owner_id === null;
        @endphp
        @if ($active)
            {{-- Ownership first (R-46 polish, D-2202): who owns it, how far it has escalated, and the one action that ends "nobody". --}}
            <section class="ops-own" data-tone="{{ $unowned ? 'stop' : ($ownedByMe ? 'go' : 'caution') }}" aria-labelledby="own-h">
                <div class="ops-own__text">
                    <h2 id="own-h" class="ops-own__who">@if($unowned)<x-eh.icon name="siren" />@endif{{ $ownedByMe ? 'You own this incident' : $ownerText }}</h2>
                    <p class="ops-own__level">{{ $levelText }}@if ($nextText) · {{ $nextText }}@endif</p>
                </div>
                <div class="ops-own__actions">
                    @if (! $canAct)
                        <p class="ops-note ops-note--small">Your role reads safety incidents; a safety officer or an admin acts on them.</p>
                    @elseif (in_array($st, ['open', 'escalated'], true))
                        <form data-ops-action method="post" action="{{ route('safety.ops.incidents.acknowledge', $incident->id) }}" data-ops-done="{{ $unowned ? 'Acknowledged. You own this incident.' : 'Acknowledged.' }}">
                            <x-eh.button type="submit" :variant="$unowned ? 'danger' : 'primary'" icon="check">{{ $unowned ? 'Acknowledge and take ownership' : 'Acknowledge' }}</x-eh.button>
                        </form>
                        @if (! $unowned && ! $ownedByMe)
                            <form data-ops-action method="post" action="{{ route('safety.ops.incidents.assign', $incident->id) }}" data-ops-done="You own this incident now.">
                                <input type="hidden" name="owner_id" value="{{ $me }}">
                                <x-eh.button type="submit" variant="secondary">Take ownership</x-eh.button>
                            </form>
                        @endif
                    @elseif (! $ownedByMe)
                        <form data-ops-action method="post" action="{{ route('safety.ops.incidents.assign', $incident->id) }}" data-ops-done="You own this incident now.">
                            <input type="hidden" name="owner_id" value="{{ $me }}">
                            <x-eh.button type="submit" :variant="$unowned ? 'danger' : 'primary'" icon="check">Take ownership</x-eh.button>
                        </form>
                    @else
                        <p class="ops-note ops-note--small">Add a note for every call you make, then resolve it when the person is safe.</p>
                    @endif
                </div>
            </section>
        @endif
        <x-eh.banner tone="safety" title="Dot.Ehail does not dispatch police or an ambulance" :emergency="true" style="margin-bottom:var(--eh-space-4)">If someone is in danger, call. Record every call you make as a note.</x-eh.banner>

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="where-h">
                    <div class="ops-panel__head"><h2 id="where-h">Where</h2><span class="ops-panel__meta">{{ $where ? 'from the '.$where['source'] : 'no position' }}</span></div>
                    <div class="ops-where">
                        <div class="ops-where__words">
                            @if ($where)
                                <p class="ops-where__place"><x-eh.icon name="pin" />{{ $where['words'] }}</p>
                                @if ($where['relative'] && $where['relative'] !== $where['words'])<p class="ops-where__rel">{{ $where['relative'] }}</p>@endif
                                @if ($where['source'] === 'reported position')
                                    <p class="ops-where__meta">Sent {{ \App\Support\Ui\LocalTime::time($incident->location_captured_at, true) }} {{ \App\Support\Ui\LocalTime::label() }}, accurate to about {{ $incident->location_accuracy_m ?? '?' }} m</p>
                                @else
                                    <p class="ops-where__meta">The {{ $incident->reporter_role ?? 'reporter' }}'s phone sent no position; this is the {{ $where['source'] }}.</p>
                                @endif
                                <p class="ops-where__gps">GPS for emergency services: <span class="num">{{ number_format($where['lat'], 5) }}, {{ number_format($where['lng'], 5) }}</span></p>
                            @else
                                <p class="ops-where__place">No position was sent and the trip has none recorded.</p>
                            @endif
                            @if ($driverWhere)<p class="ops-where__rel">Driver when it was raised: {{ \Illuminate\Support\Str::lcfirst($driverWhere) }}</p>@endif
                        </div>
                        @if ($incidentMap !== [])
                            <div class="eh-stopmap eh-stopmap--tall ops-where__map" data-eh-stopmap data-map='@json($incidentMap)'>
                                <div data-eh-map-canvas role="img" aria-label="Map of the incident: {{ $where['words'] ?? 'trip stops' }}{{ isset($incidentMap['trail']) ? ', with the driver\'s trail before it was raised' : '' }}"></div>
                                <p class="eh-stopmap__status" data-eh-stopmap-status role="status"></p>
                            </div>
                        @endif
                    </div>
                    @if ($incidentMap !== [])
                        <ul class="ops-legend" aria-label="Map key">
                            @isset($incidentMap['sos'])<li><span class="ops-dot ops-dot--sos"></span>Reported position</li>@endisset
                            @isset($incidentMap['driver'])<li><span class="ops-dot ops-dot--driver"></span>Driver's last position</li>@endisset
                            @isset($incidentMap['trail'])<li><span class="ops-dot ops-dot--trail"></span>Driver's trail before it</li>@endisset
                            @isset($incidentMap['pickup'])<li><span class="ops-dot ops-dot--pickup"></span>Pickup</li>@endisset
                            @isset($incidentMap['dropoff'])<li><span class="ops-dot ops-dot--dropoff"></span>Drop-off</li>@endisset
                        </ul>
                    @endif
                </section>

                <section class="ops-panel" aria-labelledby="ctx-h">
                    <div class="ops-panel__head"><h2 id="ctx-h">What was reported</h2><span class="ops-panel__meta">{{ \App\Support\Ui\LocalTime::format($incident->created_at, 'd M Y H:i:s') }} {{ \App\Support\Ui\LocalTime::label() }} · source {{ str_replace('_', ' ', (string) $incident->source) }}</span></div>
                    <dl class="ops-facts">
                        <dt>Raised by</dt><dd>{{ $incident->reporter?->name ?? 'System' }} ({{ $incident->reporter_role ?? 'system' }})</dd>
                        @if ($incident->description)<dt>Their words</dt><dd>{{ $incident->description }}</dd>@endif
                        @if ($incident->ride)
                            <dt>Ride</dt><dd>@can('ops.rides.view')<a class="eh-link" href="{{ route('ops.rides.show', $incident->ride_id) }}">Ride {{ $incident->ride_id }}</a>@else Ride {{ $incident->ride_id }} @endcan · <x-eh.status :status="$incident->ride->status" /></dd>
                            <dt>Passenger</dt><dd>{{ $incident->ride->passenger?->name }}</dd>
                            <dt>Driver</dt><dd>{{ $incident->ride->driver?->name ?? 'none assigned' }}</dd>
                            <dt>Route</dt><dd>{{ \App\Support\Ui\PlaceNames::short((string) $incident->ride->pickup_address) }} to {{ \App\Support\Ui\PlaceNames::short((string) $incident->ride->dropoff_address) }}</dd>
                        @endif
                        @if (isset($ctx['vehicle']) && is_array($ctx['vehicle']))
                            <dt>Vehicle</dt><dd>@if (! empty($ctx['vehicle']['registration']))<span class="eh-plate">{{ $ctx['vehicle']['registration'] }}</span> @endif{{ $ctx['vehicle']['description'] ?? '' }}</dd>
                        @endif
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
                                        <td>{{ $n->recipient_label ? $n->displayLabel() : str_replace('_', ' ', (string) $n->recipient_kind) }}</td>
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
                    <div class="ops-panel__head"><h2 id="tl-h">Timeline</h2><span class="ops-panel__meta">times in {{ \App\Support\Ui\LocalTime::label() }}</span></div>
                    <ol class="ops-route">
                        @foreach ($incident->events->sortBy('id') as $event)
                            @php($data = is_array($event->data) ? $event->data : [])
                            <li data-kind="{{ in_array($event->type, ['escalated', 'opened'], true) ? 'stop' : ($event->type === 'note' ? 'side' : 'main') }}" @if($loop->last) data-current @endif>
                                <span class="ops-route__stop" aria-hidden="true"></span>
                                <span class="ops-route__what">{{ ucfirst(str_replace('_', ' ', (string) $event->type)) }}</span>
                                <time class="ops-route__when">{{ $event->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i:s') }}</time>
                                <span class="ops-route__detail">{{ $event->actor_id ? 'by '.\App\Support\Ui\PersonNames::of($event->actor_id) : 'system' }}{{ isset($data['note']) && is_string($data['note']) ? ' · "'.$data['note'].'"' : '' }}{{ isset($data['reason']) && is_string($data['reason']) ? ' · '.str_replace('_', ' ', $data['reason']) : '' }}{{ isset($data['level']) ? ' · level '.$data['level'] : '' }}</span>
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
                        <div class="ops-panel__head"><h2 id="act-h">Act</h2><span class="ops-panel__meta">{{ $ownedByMe ? 'you own it' : $ownerText }}</span></div>
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
