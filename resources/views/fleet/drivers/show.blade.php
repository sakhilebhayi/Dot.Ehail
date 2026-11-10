@php
    $stateLabel = ['online' => 'Online', 'on_trip' => 'On a trip', 'paused' => 'Paused', 'offline' => 'Offline'];
    $docState = fn (string $s) => \App\Domain\Fleet\FleetAlerts::stateLabel($s);
    $blocking = $documents ? collect($documents['items'])->where('satisfied', false) : collect();
    $pct = fn (?int $bp) => $bp === null ? null : number_format($bp / 100, 0).'%';
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Driver" :heading="$driver->user?->name ?? 'Driver'"
    :line="match (true) {
        $driver->status === 'pending' => 'Applied to join this fleet on '.$driver->created_at->format('j M Y').'.',
        $driver->status === 'suspended' => 'Suspended by operations.',
        $blocking->isNotEmpty() => 'Cannot go online: '.$blocking->count().' '.\Illuminate\Support\Str::plural('document', $blocking->count()).' to sort out.',
        default => 'Documents in order.',
    }">
    <x-slot:actions><x-eh.button :href="route('fleet.drivers')" variant="ghost" icon="arrow-left">All drivers</x-eh.button></x-slot:actions>

    <div class="fl-grid">
        <div class="fl-stack">
            <section class="fl-panel" aria-labelledby="docs-title">
                <div class="fl-panel__head">
                    <h2 id="docs-title">Documents</h2>
                    <span class="fl-panel__meta">Status from verification. The files stay with the driver and operations.</span>
                </div>
                @if ($documents === null)
                    <p class="fl-note">Shown once the driver is approved.</p>
                @else
                    <table class="fl-table fl-table--stack" style="margin-inline: calc(-1 * var(--eh-space-5)); width: calc(100% + 2 * var(--eh-space-5))">
                        <thead><tr><th scope="col">Document</th><th scope="col">Status</th><th scope="col" class="num">Expires</th></tr></thead>
                        <tbody>
                            @foreach ($documents['items'] as $item)
                                <tr>
                                    <td>{{ $item['label'] }}</td>
                                    <td><x-eh.badge :tone="$item['satisfied'] ? ($item['expiring_soon'] ? 'caution' : 'go') : 'stop'">{{ ucfirst($docState($item['state'])) }}</x-eh.badge>@if ($item['renewal_pending'])<span class="fl-sub">Renewal waiting for review</span>@endif</td>
                                    <td class="num" @if($item['expires_on']) data-label="Expires" @endif>{!! $item['expires_on'] ? e(\Illuminate\Support\Carbon::parse($item['expires_on'])->format('j M Y')) : ($item['satisfied'] ? '<span class="fl-dash">No expiry</span>' : '<span class="fl-dash">&#8212;</span>') !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="fl-panel" aria-labelledby="perf-title">
                <div class="fl-panel__head"><h2 id="perf-title">This month</h2></div>
                @if ($performance)
                    <dl class="fl-strip" style="--fl-cells: 4; background: var(--eh-surface-2)">
                        <div class="fl-strip__cell"><dt>Trips</dt><dd>{{ $performance['trips'] }}</dd></div>
                        <div class="fl-strip__cell"><dt>Cancelled</dt><dd>{{ $performance['cancellations'] }}</dd></div>
                        <div class="fl-strip__cell"><dt>Completion</dt><dd>{!! $pct($performance['completion_bp']) ?? '<span class="fl-dash">&#8212;</span>' !!}</dd></div>
                        <div class="fl-strip__cell"><dt>Rating</dt><dd style="font-size: var(--eh-text-xl)">{{ $performance['rating']['label'] }}</dd><dd class="fl-strip__note">{{ $performance['rating']['count'] }} ratings</dd></div>
                    </dl>
                @else
                    <p class="fl-note">No trips yet.</p>
                @endif
            </section>

            <section class="fl-panel" aria-labelledby="hist-title">
                <div class="fl-panel__head"><h2 id="hist-title">Fleet vehicles history</h2></div>
                @if ($assignments->isEmpty())
                    <p class="fl-note">This driver has not had a fleet vehicle.</p>
                @else
                    <ul class="fl-items">
                        @foreach ($assignments as $a)
                            <li style="grid-template-columns: auto minmax(0,1fr)">
                                <span class="fl-plate">{{ $a->vehicle?->plate_number }}</span>
                                <p class="fl-items__detail" style="margin:0">{{ $a->started_at->format('j M Y H:i') }} to {{ $a->ended_at?->format('j M Y H:i') ?? 'now' }}{{ $a->end_reason ? ' ('.str_replace('_', ' ', $a->end_reason).')' : '' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="fl-stack" aria-label="Driver details">
            <section class="fl-panel">
                <dl class="fl-kv">
                    <dt>Status</dt><dd><x-eh.badge :tone="['approved' => 'go', 'pending' => 'caution', 'suspended' => 'stop', 'rejected' => 'neutral'][$driver->status] ?? 'neutral'">{{ ucfirst($driver->status) }}</x-eh.badge></dd>
                    <dt>Now</dt><dd>{{ $stateLabel[$availability?->state ?? 'offline'] ?? 'Offline' }}</dd>
                    <dt>On Dot.Ehail since</dt><dd>{{ $driver->user?->created_at?->format('M Y') }}</dd>
                    @if ($masked)
                        <dt>Licence</dt><dd>{{ \App\Support\DriverIdentifiers::mask($driver->license_number) }}</dd>
                        <dt>ID</dt><dd>{{ \App\Support\DriverIdentifiers::mask($driver->id_number) }}</dd>
                    @endif
                </dl>
            </section>
            <section>
                <h2 class="fl-rail-title">May drive</h2>
                <div class="fl-panel">
                    @if ($permits->isEmpty() && $ownVehicles->isEmpty())
                        <p class="fl-note">No vehicle yet. Allow this driver on a fleet vehicle from that vehicle's page.</p>
                    @else
                        <ul class="fl-items">
                            @foreach ($ownVehicles as $v)
                                <li style="grid-template-columns: auto minmax(0,1fr)"><span class="fl-plate">{{ $v->plate_number }}</span><p class="fl-items__detail" style="margin:0">Their own {{ $v->make }} {{ $v->model }}</p></li>
                            @endforeach
                            @foreach ($permits as $permit)
                                <li style="grid-template-columns: auto minmax(0,1fr)"><a class="fl-plate" href="{{ route('fleet.vehicles.show', $permit->vehicle_id) }}">{{ $permit->vehicle?->plate_number }}</a><p class="fl-items__detail" style="margin:0">Fleet {{ $permit->vehicle?->make }} {{ $permit->vehicle?->model }}{{ (int) $permit->vehicle?->driver_profile_id === $driver->id ? ', has it now' : '' }}</p></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-fleet.page>
