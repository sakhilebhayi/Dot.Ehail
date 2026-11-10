{{-- Legacy fleet page (/fleets/{fleet}), kept working and moved into the fleet shell (R-46). The portal at /fleet has the full tools. --}}
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Applications" :heading="$fleet->name"
    :line="$fleet->isApproved() ? 'Driver applications and the vehicle register.' : 'This fleet is pending platform review. Applications can only be decided by a platform operator until it is approved.'">
    <x-slot:actions>
        @if (app(\App\Domain\Fleet\FleetAccess::class)->isMember(auth()->user(), $fleet))
            <x-eh.button :href="route('fleet.home', ['fleet' => $fleet->id])" variant="secondary" icon="fleet">Open the fleet portal</x-eh.button>
        @endif
    </x-slot:actions>

    @if ($errors->has('status'))
        <x-eh.banner tone="stop" :title="$errors->first('status')" />
    @endif

    <section class="fl-panel" aria-labelledby="pending-title">
        <div class="fl-panel__head"><h2 id="pending-title">Pending ({{ $pending->count() }})</h2></div>
        @forelse ($pending as $profile)
            <div class="fl-stack fl-stack--tight" style="padding: var(--eh-space-3) 0; {{ $loop->first ? '' : 'border-top: 1px solid var(--eh-rule);' }}">
                <div>
                    <p class="fl-items__title">{{ $profile->user?->name }}</p>
                    <p class="fl-items__who">
                        @if ($masked)
                            License {{ \App\Support\DriverIdentifiers::mask($profile->license_number) }} · ID {{ \App\Support\DriverIdentifiers::mask($profile->id_number) }} · <a class="eh-link" href="{{ route('drivers.show', $profile) }}">Open profile</a> (logged)
                        @else
                            Applied {{ $profile->created_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}. Identity documents are checked by operations.
                        @endif
                    </p>
                    @foreach ($profile->vehicles as $vehicle)
                        <p class="fl-items__detail"><span class="fl-plate">{{ $vehicle->plate_number }}</span> {{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} ({{ $vehicle->color }})</p>
                    @endforeach
                    @if ($masked && $profile->documents->isNotEmpty())
                        <p class="fl-items__detail">
                            @foreach ($profile->documents as $document)
                                <a class="eh-link" href="{{ route('drivers.documents.show', $document) }}">{{ ucfirst(str_replace('_', ' ', $document->type)) }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                </div>

                @can('reviewDriver', [$fleet, $profile])
                    <div class="fl-inline">
                        <form method="POST" action="{{ route('fleets.drivers.approve', [$fleet, $profile]) }}" class="fl-inline">
                            @csrf
                            <x-eh.button type="submit" size="sm" icon="check">Approve</x-eh.button>
                        </form>
                        <form method="POST" action="{{ route('fleets.drivers.reject', [$fleet, $profile]) }}" class="fl-inline">
                            @csrf
                            <label class="eh-sr-only" for="legacy-reason-{{ $profile->id }}">Reason for rejecting</label>
                            <input id="legacy-reason-{{ $profile->id }}" class="eh-input" type="text" name="reason" placeholder="Reason for rejecting" required>
                            <x-eh.button type="submit" size="sm" variant="danger">Reject</x-eh.button>
                        </form>
                    </div>
                @else
                    <p class="fl-note">You can't decide this application. It needs review by someone other than the applicant{{ $fleet->isApproved() ? '' : ', and a platform operator while the fleet is pending' }}.</p>
                @endcan
            </div>
        @empty
            <p class="fl-note">No pending applications.</p>
        @endforelse
    </section>

    @if ($vehicles !== null)
        <section class="fl-panel fl-panel--flush" aria-labelledby="register-title">
            <div class="fl-panel__head"><h2 id="register-title">Vehicle register ({{ $vehicles->count() }})</h2></div>
            @if ($vehicles->isEmpty())
                <p class="fl-note" style="padding: 0 var(--eh-space-5) var(--eh-space-4)">No vehicles registered in this fleet yet.</p>
            @else
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Driven by</th><th scope="col">Active</th></tr></thead>
                    <tbody>
                        @foreach ($vehicles as $vehicle)
                            <tr>
                                <td><span class="fl-plate">{{ $vehicle->plate_number }}</span></td>
                                <td>{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}<span class="fl-sub">{{ $vehicle->color }}, {{ $vehicle->seats }} seats, {{ $vehicle->type === 'suv' ? 'SUV' : ucfirst($vehicle->type) }}</span></td>
                                <td data-label="Driver">{{ $vehicle->driverProfile?->user?->name ?? 'unknown' }} ({{ $vehicle->driverProfile?->status }})</td>
                                <td><x-eh.badge :tone="$vehicle->is_active ? 'go' : 'neutral'">{{ $vehicle->is_active ? 'Active' : 'Inactive' }}</x-eh.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    <section class="fl-panel" aria-labelledby="reviewed-title">
        <div class="fl-panel__head"><h2 id="reviewed-title">Reviewed</h2></div>
        @forelse ($reviewed as $profile)
            <div style="display:flex;justify-content:space-between;gap:var(--eh-space-3);align-items:center;padding: var(--eh-space-2) 0; {{ $loop->first ? '' : 'border-top: 1px solid var(--eh-rule);' }}">
                <div>
                    <p class="fl-items__title">{{ $profile->user?->name }}</p>
                    @if ($masked)<p class="fl-items__who">{{ \App\Support\DriverIdentifiers::mask($profile->license_number) }}</p>@endif
                </div>
                <x-eh.badge :tone="['approved' => 'go', 'rejected' => 'stop'][$profile->status] ?? 'caution'">{{ ucfirst($profile->status) }}</x-eh.badge>
            </div>
        @empty
            <p class="fl-note">No reviewed applications yet.</p>
        @endforelse
    </section>
</x-fleet.page>
