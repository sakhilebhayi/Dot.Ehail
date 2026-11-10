@php
    $rate = fn ($v) => $v === null ? null : number_format($v, 1).'%';
    $docTypes = (array) config('ehail_safety.markets.ZA.document_types', []);
    $finding = match (true) {
        $suspension !== null => ['Suspended since '.$suspension->suspended_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y').': '.$suspension->reasonLabel().($suspension->appeal_status === 'open' ? '. An appeal is waiting for a decision.' : '.'), 'stop'],
        $profile->status === 'pending' => ['Application pending with fleet '.($profile->fleet?->name ?? $profile->fleet_id).': the fleet owner decides.', 'caution'],
        default => [$factsAll['completed_all'].' completed '.\Illuminate\Support\Str::plural('trip', $factsAll['completed_all']).'; '.$facts30['completed_window'].' in the last 30 days.', null],
    };
@endphp
<x-ops-layout :title="$driver->name">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.drivers') }}">Drivers</a> / {{ $driver->name }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>{{ $driver->name }} @include('ops.partials.profile-status', ['status' => $profile->status, 'documents' => app(\App\Domain\Verification\VerificationService::class)->driverStatus($profile)['status']]) @include('ops.partials.state', ['row' => $availability])</h1>
                <p class="ops-head__finding" @if($finding[1]) data-tone="{{ $finding[1] }}" @endif>{{ $finding[0] }}</p>
            </div>
        </header>
        @include('ops.partials.flash')

        <dl class="ops-figures" aria-label="Last 30 days">
            <div><dt>Trips completed, 30 days</dt><dd>{{ number_format($facts30['completed_window']) }}</dd></div>
            <div><dt title="accepted / (accepted + declined + timed out) offers sent in the last 30 days">Offers accepted</dt><dd @if($facts30['acceptance_rate'] === null) data-empty @endif>{{ $rate($facts30['acceptance_rate']) ?? '-' }}<small>{{ $facts30['offers_accepted'] }} of {{ $facts30['offers_accepted'] + $facts30['offers_declined'] + $facts30['offers_expired'] }}</small></dd></div>
            <div data-tone="{{ $facts30['cancelled_after_accepting'] + $facts30['backed_out'] > 0 ? 'caution' : '' }}"><dt title="Rides this driver cancelled, plus times they handed a ride back to search, in the last 30 days">Cancelled after accepting</dt><dd>{{ $facts30['cancelled_after_accepting'] + $facts30['backed_out'] }}<small>{{ $facts30['backed_out'] }} handed back</small></dd></div>
            <div><dt title="Bands, not decimals; ratings excluded on appeal are left out">Rating</dt><dd style="font-size:var(--eh-text-md)">{{ $factsAll['rating']['label'] }}<small>{{ $factsAll['rating']['count'] }} {{ \Illuminate\Support\Str::plural('rating', $factsAll['rating']['count']) }}</small></dd></div>
            <div><dt>Trips completed, all time</dt><dd>{{ number_format($factsAll['completed_all']) }}</dd></div>
        </dl>

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel ops-panel--flush" aria-labelledby="trips-h">
                    <div class="ops-panel__head"><h2 id="trips-h">Recent trips</h2><span class="ops-panel__meta">latest 15</span></div>
                    @if ($trips->isEmpty())
                        <p class="ops-none">No trip with this driver yet.</p>
                    @else
                        <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                            <thead><tr><th>Ride</th><th>Status</th><th>Passenger</th><th class="num">Fare</th><th class="num">Requested</th></tr></thead>
                            <tbody>
                                @foreach ($trips as $ride)
                                    <tr>
                                        <td>@can('ops.rides.view')<a href="{{ route('ops.rides.show', $ride->id) }}">{{ $ride->id }}</a>@else{{ $ride->id }}@endcan</td>
                                        <td><x-eh.status :status="$ride->status" /></td>
                                        <td>{{ $ride->passenger?->name }}</td>
                                        <td class="num">@if($ride->final_fare_minor !== null)<x-eh.money :minor="$ride->final_fare_minor" :currency="$ride->currency ?? 'ZAR'" />@elseif($ride->final_fare !== null){{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}@else<span class="ops-dash">-</span>@endif</td>
                                        <td class="num">{{ ($ride->requested_at ?? $ride->created_at)?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>

                @if ($earnings !== null)
                    <section class="ops-panel ops-panel--flush" aria-labelledby="earn-h">
                        <div class="ops-panel__head"><h2 id="earn-h">Earnings and payouts</h2><span class="ops-panel__meta">from driver_earnings and payouts</span></div>
                        @if ($earnings->isEmpty())
                            <p class="ops-none">No earnings are recorded for this driver.</p>
                        @else
                            <table class="ops-table ops-table--compact">
                                <thead><tr><th>Earnings status</th><th class="num">Entries</th><th class="num">Net</th></tr></thead>
                                <tbody>
                                    @foreach ($earnings as $row)
                                        <tr><td>{{ ucfirst($row->status) }}</td><td class="num">{{ $row->n }}</td><td class="num"><x-eh.money :minor="(int) $row->net_minor" :currency="$row->currency" /></td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        @if ($payouts->isNotEmpty())
                            <table class="ops-table ops-table--compact">
                                <thead><tr><th>Payout</th><th>Status</th><th class="num">Earnings</th><th class="num">Cash netted</th><th class="num">Paid out</th></tr></thead>
                                <tbody>
                                    @foreach ($payouts as $payout)
                                        <tr>
                                            <td>@can('ops.finance.view')<a href="{{ route('ops.finance.batch', $payout->payout_batch_id) }}">{{ $payout->reference ?? 'Payout '.$payout->id }}</a>@else{{ $payout->reference ?? 'Payout '.$payout->id }}@endcan</td>
                                            <td>{{ ucfirst((string) $payout->status) }}</td>
                                            <td class="num"><x-eh.money :minor="(int) $payout->earnings_minor" :currency="$payout->currency" /></td>
                                            <td class="num"><x-eh.money :minor="(int) $payout->cash_offset_minor" :currency="$payout->currency" /></td>
                                            <td class="num"><x-eh.money :minor="(int) $payout->amount_minor" :currency="$payout->currency" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endif

                @if ($documents !== null)
                    <section class="ops-panel ops-panel--flush" aria-labelledby="docs-h">
                        <div class="ops-panel__head"><h2 id="docs-h">Documents</h2><span class="ops-panel__meta">opening one is logged</span></div>
                        @if ($documents->isEmpty())
                            <p class="ops-none">No document has been uploaded.</p>
                        @else
                            <table class="ops-table ops-table--compact">
                                <thead><tr><th>Document</th><th>Status</th><th class="num">Expires</th><th class="num">Uploaded</th></tr></thead>
                                <tbody>
                                    @foreach ($documents as $doc)
                                        @php($docStatus = $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status)
                                        <tr>
                                            <td><a href="{{ route('ops.verification.show', $doc->id) }}">{{ $docTypes[$doc->type]['label'] ?? str_replace('_', ' ', $doc->type) }}</a>@if($doc->vehicle_id)<span class="sub">vehicle {{ $doc->vehicle_id }}</span>@endif</td>
                                            <td><x-eh.badge :tone="match($docStatus){'approved'=>'go','pending'=>'caution','rejected','expired'=>'stop',default=>'neutral'}">{{ ucfirst(str_replace('_', ' ', $docStatus)) }}</x-eh.badge></td>
                                            <td class="num">{{ $doc->expires_on ? \Illuminate\Support\Carbon::parse($doc->expires_on)->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') : '-' }}</td>
                                            <td class="num">{{ ($doc->submitted_at ?? $doc->created_at)?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endif
            </div>

            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="susp-h">
                    <div class="ops-panel__head"><h2 id="susp-h">Suspension</h2></div>
                    @if ($suspension)
                        <dl class="ops-facts">
                            <dt>Reason</dt><dd>{{ $suspension->reasonLabel() }}</dd>
                            @if ($suspension->note)<dt>Note</dt><dd>{{ $suspension->note }}</dd>@endif
                            <dt>By</dt><dd>{{ $suspension->suspender?->name ?? 'an operator' }}, {{ $suspension->suspended_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}</dd>
                            <dt>Appeal</dt><dd>{{ match($suspension->appeal_status) { 'open' => 'Waiting for a decision', 'upheld' => 'Upheld', 'rejected' => 'Rejected', default => 'None yet' } }}</dd>
                            @if ($suspension->appeal_text)<dt>Driver wrote</dt><dd>{{ $suspension->appeal_text }}</dd>@endif
                        </dl>
                        @if ($canSuspend)
                            <div class="ops-actions" style="margin-top:var(--eh-space-4)">
                                @if ($suspension->appeal_status === 'open')
                                    <details open>
                                        <summary>Decide the appeal <span class="ops-hint">not by the person who suspended</span></summary>
                                        <form class="ops-act" method="POST" action="{{ route('ops.drivers.appeal-decide', $suspension->id) }}">
                                            @csrf
                                            <x-eh.field label="Decision note" name="note" type="textarea" required minlength="3" maxlength="1000" id="appeal-note" />
                                            <div class="ops-act__row">
                                                <x-eh.button type="submit" size="sm" name="decision" value="upheld">Uphold and reinstate</x-eh.button>
                                                <x-eh.button type="submit" size="sm" variant="secondary" name="decision" value="rejected">Reject the appeal</x-eh.button>
                                            </div>
                                        </form>
                                    </details>
                                @endif
                                <details>
                                    <summary>Lift the suspension</summary>
                                    <form class="ops-act" method="POST" action="{{ route('ops.drivers.lift', $suspension->id) }}">
                                        @csrf
                                        <x-eh.field label="Why it is lifted" name="note" type="textarea" required minlength="3" maxlength="1000" id="lift-note" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Lift suspension</x-eh.button></div>
                                    </form>
                                </details>
                            </div>
                        @endif
                    @else
                        <p class="ops-none" style="padding-top:0">Not suspended.</p>
                        @if ($canSuspend && $profile->status !== 'pending')
                            <div class="ops-actions">
                                <details>
                                    <summary>Suspend this driver <span class="ops-hint">takes them offline now</span></summary>
                                    <form class="ops-act" method="POST" action="{{ route('ops.drivers.suspend', $driver->id) }}" data-ops-confirm="Suspend {{ $driver->name }}? They go offline now and cannot go online until the suspension is lifted.">
                                        @csrf
                                        <x-eh.select label="Reason" name="reason_code" :options="$reasons" id="susp-reason" />
                                        <x-eh.field label="Note (the driver can appeal against it)" name="note" type="textarea" maxlength="1000" id="susp-note" :optional="true" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Suspend</x-eh.button></div>
                                    </form>
                                </details>
                            </div>
                        @endif
                    @endif
                    @if ($history->where('lifted_at', '!=', null)->isNotEmpty())
                        <h3 style="font-size:var(--eh-text-sm);margin:var(--eh-space-4) 0 var(--eh-space-2)">Earlier suspensions</h3>
                        <ul class="ops-queue" style="margin:0 calc(-1 * var(--eh-space-5))">
                            @foreach ($history->where('lifted_at', '!=', null) as $past)
                                <li><span class="ops-queue__title">{{ $past->reasonLabel() }}</span><span class="ops-queue__meta">{{ $past->suspended_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }} to {{ $past->lifted_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }} · lifted by {{ $past->lifter?->name ?? 'an operator' }}{{ $past->appeal_status !== 'none' ? ' · appeal '.$past->appeal_status : '' }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="ops-panel" aria-labelledby="profile-h">
                    <div class="ops-panel__head"><h2 id="profile-h">Profile</h2></div>
                    <dl class="ops-facts">
                        <dt>Email</dt><dd>{{ $driver->email }}</dd>
                        <dt>Fleet</dt><dd>{{ $profile->fleet?->name ?? 'Fleet '.$profile->fleet_id }} {{ $profile->fleet && $profile->fleet->status !== 'approved' ? '(not approved yet)' : '' }}</dd>
                        <dt>Joined</dt><dd class="num">{{ $profile->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }}</dd>
                        @if ($profile->reviewed_at)<dt>Reviewed</dt><dd class="num">{{ $profile->reviewed_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }}</dd>@endif
                        @if ($profile->rejected_reason)<dt>Rejected because</dt><dd>{{ $profile->rejected_reason }}</dd>@endif
                        @if ($availability?->last_heartbeat_at)<dt>Last heartbeat</dt><dd class="num">{{ $availability->last_heartbeat_at->diffForHumans() }}</dd>@endif
                    </dl>
                    @if ($profile->vehicles->isNotEmpty())
                        <h3 style="font-size:var(--eh-text-sm);margin:var(--eh-space-4) 0 var(--eh-space-2)">Vehicles</h3>
                        <ul class="ops-queue" style="margin:0 calc(-1 * var(--eh-space-5))">
                            @foreach ($profile->vehicles as $vehicle)
                                <li><span class="ops-queue__title">{{ trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model) }} · {{ $vehicle->plate_number }}</span><span class="ops-queue__meta">{{ ucfirst((string) $vehicle->type) }}, {{ $vehicle->color }}{{ $vehicle->is_active ? ' · in use' : '' }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-ops-layout>
