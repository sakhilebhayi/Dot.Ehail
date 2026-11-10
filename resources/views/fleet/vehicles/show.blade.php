@php
    $docState = fn (string $s) => \App\Domain\Fleet\FleetAlerts::stateLabel($s);
    $missingDocs = collect($documents['items'])->where('satisfied', false);
    $holder = $vehicle->holder;
    $fleetOwned = $vehicle->owned_by === 'fleet';
    $failureText = fn (string $code) => match (true) {
        $code === 'vehicle_class' => 'class not accepted',
        $code === 'too_old', str_starts_with($code, 'too_old') => 'too old',
        $code === 'year_too_old' => 'too old',
        $code === 'too_few_seats' => 'too few seats',
        $code === 'doors_unknown_or_too_few' => 'doors not recorded or too few',
        str_starts_with($code, 'missing_feature:') => 'needs '.str_replace('_', ' ', substr($code, 16)),
        $code === 'no_vehicle_rules' => 'not open to vehicles yet',
        default => str_replace('_', ' ', $code),
    };
    $line = match (true) {
        $vehicle->retired_at !== null => 'Retired on '.$vehicle->retired_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y').'.',
        $vehicle->out_of_service_at !== null => 'Out of service since '.$vehicle->out_of_service_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y').': '.($vehicle->out_of_service_reason ?: 'no reason recorded').'.',
        $holder === null => 'Nobody has this vehicle. Give it to a driver you allow to drive it.',
        $missingDocs->isNotEmpty() => 'With '.($holder->user?->name ?? 'a driver').'. Cannot go online: '.$missingDocs->count().' '.\Illuminate\Support\Str::plural('document', $missingDocs->count()).' to sort out.',
        default => 'With '.($holder->user?->name ?? 'a driver').'. Documents in order.',
    };
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Vehicle {{ $vehicle->plate_number }}" :heading="$vehicle->year.' '.$vehicle->make.' '.$vehicle->model" :line="$line">
    <x-slot:actions>
        <span class="fl-plate fl-plate--lg" aria-label="Number plate {{ $vehicle->plate_number }}">{{ $vehicle->plate_number }}</span>
    </x-slot:actions>

    <div class="fl-grid">
        <div class="fl-stack">
            {{-- Who has it --}}
            @if ($fleetOwned)
                <section class="fl-panel" aria-labelledby="who-title">
                    <div class="fl-panel__head">
                        <h2 id="who-title">Who drives it</h2>
                        <span class="fl-panel__meta">One driver has it at a time. It changes hands only while that driver is offline.</span>
                    </div>
                    <dl class="fl-kv">
                        <dt>Has it now</dt>
                        <dd>
                            @if ($holder)
                                <a class="fl-name" href="{{ route('fleet.drivers.show', $holder->id) }}">{{ $holder->user?->name }}</a>
                                since {{ $assignment?->started_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y H:i') ?? 'before assignments were recorded' }}{{ $inUse ? ', working in it now' : '' }}
                            @else
                                <span class="fl-dash">Nobody</span>
                            @endif
                        </dd>
                    </dl>

                    @if ($canWrite && ! $vehicle->retired_at)
                        <div class="fl-panel__foot">
                            @if ($permits->isNotEmpty())
                                <form method="POST" action="{{ route('fleet.vehicles.assign', $vehicle) }}" class="fl-inline">
                                    @csrf
                                    <label class="eh-sr-only" for="assign-to">Give to</label>
                                    <div class="eh-select-wrap">
                                        <select id="assign-to" name="driver_profile_id" class="eh-select" required>
                                            @foreach ($permits as $permit)
                                                <option value="{{ $permit->driver_profile_id }}" @selected($holder?->id === $permit->driver_profile_id)>{{ $permit->driverProfile?->user?->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-eh.icon name="chevron-down" />
                                    </div>
                                    <x-eh.button type="submit" size="sm" :disabled="$inUse">Give to this driver</x-eh.button>
                                </form>
                            @endif
                            @if ($holder)
                                <form method="POST" action="{{ route('fleet.vehicles.unassign', $vehicle) }}" class="fl-inline">
                                    @csrf
                                    <x-eh.button type="submit" size="sm" variant="secondary" :disabled="$inUse">Take back</x-eh.button>
                                </form>
                            @endif
                            @if ($inUse)<p class="fl-note">{{ $holder?->user?->name ?? 'The driver' }} is online or on a trip in it. It can change hands once they go offline.</p>@endif
                        </div>
                    @endif

                    <h3 class="fl-rail-title" style="margin-top: var(--eh-space-5)">Allowed to drive it</h3>
                    @if ($permits->isEmpty())
                        <p class="fl-note">Nobody yet.</p>
                    @else
                        <ul class="fl-items">
                            @foreach ($permits as $permit)
                                <li style="grid-template-columns: minmax(0,1fr) auto">
                                    <p class="fl-items__title">{{ $permit->driverProfile?->user?->name }}</p>
                                    @if ($canWrite && $holder?->id !== $permit->driver_profile_id)
                                        <form method="POST" action="{{ route('fleet.vehicles.permits.revoke', [$vehicle, $permit->driver_profile_id]) }}" class="fl-inline">
                                            @csrf @method('DELETE')
                                            <x-eh.button type="submit" size="sm" variant="ghost">Withdraw</x-eh.button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($canWrite && $candidates->isNotEmpty() && ! $vehicle->retired_at)
                        <form method="POST" action="{{ route('fleet.vehicles.permits.grant', $vehicle) }}" class="fl-inline" style="margin-top: var(--eh-space-3)">
                            @csrf
                            <label class="eh-sr-only" for="permit-to">Allow a driver</label>
                            <div class="eh-select-wrap">
                                <select id="permit-to" name="driver_profile_id" class="eh-select" required>
                                    @foreach ($candidates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->user?->name }}</option>
                                    @endforeach
                                </select>
                                <x-eh.icon name="chevron-down" />
                            </div>
                            <x-eh.button type="submit" size="sm" variant="secondary" icon="plus">Allow to drive</x-eh.button>
                        </form>
                    @endif
                </section>
            @else
                <section class="fl-panel" aria-labelledby="who-title">
                    <div class="fl-panel__head"><h2 id="who-title">Who drives it</h2></div>
                    <p class="fl-note">This is {{ $holder?->user?->name ?? 'a driver' }}'s own vehicle. Only they drive it, and they keep its details up to date in the driver app. The fleet records its doors, features and maintenance.</p>
                </section>
            @endif

            {{-- Documents --}}
            <section class="fl-panel fl-panel--flush" aria-labelledby="vdocs-title">
                <div class="fl-panel__head">
                    <h2 id="vdocs-title">Documents</h2>
                    <span class="fl-panel__meta">{{ $holder ? 'Uploaded by '.($holder->user?->name ?? 'the driver').' in the driver app, verified by operations.' : 'Uploaded by the driver who has it, verified by operations.' }}</span>
                </div>
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Document</th><th scope="col">Status</th><th scope="col" class="num">Expires</th></tr></thead>
                    <tbody>
                        @foreach ($documents['items'] as $item)
                            <tr>
                                <td>{{ $item['label'] }}</td>
                                <td><x-eh.badge :tone="$item['satisfied'] ? ($item['expiring_soon'] ? 'caution' : 'go') : 'stop'">{{ ucfirst($docState($item['state'])) }}</x-eh.badge>@if ($item['renewal_pending'])<span class="fl-sub">Renewal waiting for review</span>@endif</td>
                                <td class="num" @if($item['expires_on']) data-label="Expires" @endif>{!! $item['expires_on'] ? e(\Illuminate\Support\Carbon::parse($item['expires_on'])->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y')) : ($item['satisfied'] ? '<span class="fl-dash">No expiry</span>' : '<span class="fl-dash">&#8212;</span>') !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            {{-- Maintenance --}}
            <section class="fl-panel" aria-labelledby="maint-title">
                <div class="fl-panel__head">
                    <h2 id="maint-title">Maintenance</h2>
                    <span class="fl-panel__meta">
                        Next service:
                        @if ($nextService['on'] || $nextService['km'])
                            <span class="{{ $nextService['overdue'] ? 'fl-sev--stop' : ($nextService['due_soon'] ? 'fl-sev--caution' : '') }}">{{ $nextService['on']?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}{{ $nextService['on'] && $nextService['km'] ? ' or ' : '' }}{{ $nextService['km'] ? number_format($nextService['km'], 0, '.', ' ').' km' : '' }}{{ $nextService['overdue'] ? ', overdue' : ($nextService['due_soon'] ? ', due soon' : '') }}</span>
                        @else
                            not recorded
                        @endif
                    </span>
                </div>

                @if ($canMaintain)
                    <div class="fl-panel__foot" style="margin: 0 0 var(--eh-space-4)">
                        @if ($vehicle->out_of_service_at)
                            <form method="POST" action="{{ route('fleet.vehicles.back-in-service', $vehicle) }}" class="fl-inline">
                                @csrf
                                <x-eh.button type="submit" size="sm">Return to service</x-eh.button>
                            </form>
                        @else
                            <details class="fl-disclose">
                                <summary><x-eh.icon name="wrench" />Take out of service</summary>
                                <form method="POST" action="{{ route('fleet.vehicles.out-of-service', $vehicle) }}" class="fl-inline">
                                    @csrf
                                    <label class="eh-sr-only" for="oos-reason">Why</label>
                                    <input id="oos-reason" class="eh-input" name="reason" required maxlength="200" placeholder="Why, for example brakes in for repair">
                                    <x-eh.button type="submit" size="sm" variant="danger">Take out of service</x-eh.button>
                                </form>
                                <p class="fl-note" style="margin-top: var(--eh-space-2)">Nobody can go online in it until you return it. A driver online in it is taken offline; one on a trip finishes the trip first.</p>
                            </details>
                        @endif
                    </div>

                    <details class="fl-disclose" @if($errors->hasAny(['kind', 'performed_on', 'description', 'cost', 'next_service_due_on', 'next_service_due_km', 'odometer_km'])) open @endif>
                        <summary><x-eh.icon name="plus" />Record a service or repair</summary>
                        <form method="POST" action="{{ route('fleet.vehicles.maintenance.store', $vehicle) }}" class="fl-form">
                            @csrf
                            <x-eh.select label="What" name="kind" :options="$kinds" value="service" />
                            <x-eh.field label="Date" name="performed_on" type="date" :value="now()->toDateString()" />
                            <div class="fl-form__full"><x-eh.field label="What was done" name="description" type="textarea" /></div>
                            <x-eh.field label="Odometer (km)" name="odometer_km" type="number" min="0" :optional="true" :value="$vehicle->odometer_km" />
                            <x-eh.field label="Cost (R)" name="cost" :optional="true" inputmode="decimal" hint="For example 2450.00" />
                            <x-eh.field label="Next service by date" name="next_service_due_on" type="date" :optional="true" />
                            <x-eh.field label="Next service at (km)" name="next_service_due_km" type="number" min="0" :optional="true" />
                            <div class="fl-form__actions"><x-eh.button type="submit" size="sm">Save record</x-eh.button></div>
                        </form>
                    </details>
                @endif

                @if ($maintenance->isEmpty())
                    <p class="fl-note" style="margin-top: var(--eh-space-3)">No maintenance recorded yet.</p>
                @else
                    <ul class="fl-items" style="margin-top: var(--eh-space-3)">
                        @foreach ($maintenance as $record)
                            <li style="grid-template-columns: 6.5rem minmax(0,1fr) auto">
                                <p class="fl-items__who" style="margin:0">{{ $record->performed_on->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}</p>
                                <div>
                                    <p class="fl-items__title">{{ $kinds[$record->kind] ?? ucfirst($record->kind) }}</p>
                                    <p class="fl-items__detail">{{ $record->description }}</p>
                                </div>
                                <p class="fl-items__who" style="text-align:right">
                                    {{ $record->odometer_km !== null ? number_format($record->odometer_km, 0, '.', ' ').' km' : '' }}
                                    @if ($record->cost_minor !== null)<br><x-eh.money :minor="$record->cost_minor" :currency="$record->currency" />@endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="fl-stack" aria-label="Vehicle details">
            <section>
                <h2 class="fl-rail-title">Ride categories</h2>
                <div class="fl-panel">
                    @if ($catalogue->isEmpty())
                        <p class="fl-note">No ride categories are open yet.</p>
                    @else
                        <ul class="fl-cats">
                            @foreach ($catalogue as $entry)
                                <li>
                                    <span>{{ $entry['category']->name }}</span>
                                    @if ($entry['failures'] === [])
                                        <x-eh.badge tone="go" icon="check">Qualifies</x-eh.badge>
                                    @else
                                        <span class="fl-cats__why">{{ collect($entry['failures'])->map($failureText)->unique()->implode(', ') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section>
                <h2 class="fl-rail-title">Details</h2>
                <div class="fl-panel">
                    <dl class="fl-kv">
                        <dt>Class</dt><dd>{{ $vehicle->type === 'suv' ? 'SUV' : ucfirst($vehicle->type) }}</dd>
                        <dt>Colour</dt><dd>{{ $vehicle->color }}</dd>
                        <dt>Seats</dt><dd>{{ $vehicle->seats }}</dd>
                        <dt>Doors</dt><dd>{{ $vehicle->doors ?? 'Not recorded' }}</dd>
                        <dt>Odometer</dt><dd>{{ $vehicle->odometer_km !== null ? number_format($vehicle->odometer_km, 0, '.', ' ').' km' : 'Not recorded' }}</dd>
                        <dt>Features</dt><dd>{{ collect($vehicle->features ?? [])->map(fn ($f) => \Illuminate\Support\Str::before($features[$f] ?? $f, ' ('))->implode(', ') ?: 'None declared' }}</dd>
                        <dt>Owned by</dt><dd>{{ $fleetOwned ? 'The fleet' : 'The driver' }}</dd>
                    </dl>
                    @if ($canWrite)
                        <details class="fl-disclose" style="margin-top: var(--eh-space-4)" @if($errors->hasAny(['plate_number', 'make', 'model', 'year', 'color', 'type', 'seats', 'doors', 'odometer_km', 'features'])) open @endif>
                            <summary>Edit details</summary>
                            <form method="POST" action="{{ route('fleet.vehicles.update', $vehicle) }}" class="fl-form" style="grid-template-columns: minmax(0,1fr)">
                                @csrf @method('PATCH')
                                @include('fleet.vehicles._details', ['vehicle' => $vehicle])
                                <div class="fl-form__actions"><x-eh.button type="submit" size="sm">Save details</x-eh.button></div>
                            </form>
                        </details>
                    @endif
                </div>
            </section>

            @if ($canWrite && $fleetOwned)
                <section class="fl-panel">
                    @if ($vehicle->retired_at)
                        <form method="POST" action="{{ route('fleet.vehicles.reinstate', $vehicle) }}" class="fl-inline">@csrf<x-eh.button type="submit" size="sm" variant="secondary">Return to the register</x-eh.button></form>
                    @else
                        <details class="fl-disclose">
                            <summary>Retire this vehicle</summary>
                            <p class="fl-note">Sold, written off or no longer used. It is taken back from its driver and leaves the register; its history stays.</p>
                            <form method="POST" action="{{ route('fleet.vehicles.retire', $vehicle) }}" class="fl-inline" style="margin-top: var(--eh-space-3)">@csrf<x-eh.button type="submit" size="sm" variant="danger" :disabled="$inUse">Retire {{ $vehicle->plate_number }}</x-eh.button></form>
                        </details>
                    @endif
                </section>
            @endif

            @if ($history->isNotEmpty())
                <section>
                    <h2 class="fl-rail-title">Driver history</h2>
                    <div class="fl-panel">
                        <ul class="fl-items">
                            @foreach ($history as $a)
                                <li style="grid-template-columns: minmax(0,1fr)">
                                    <div><p class="fl-items__title">{{ $a->driverProfile?->user?->name }}</p><p class="fl-items__who">{{ $a->started_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }} to {{ $a->ended_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') ?? 'now' }}</p></div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif
        </aside>
    </div>
</x-fleet.page>
