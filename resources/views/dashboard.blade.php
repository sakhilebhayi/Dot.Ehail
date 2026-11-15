{{--
    The signed-in landing (R-51 dashboard, builds on R-50): where a person arrives from InfoDot. Route Line tokens only
    (the dashboard stylesheet under ds/). Not a live screen: it never polls and adds nothing to a trip screen.
    One dominant action per role (App\Support\Ui\LandingAction), marked data-primary-action. The desk (left) holds what
    needs doing and the ride ledger; the detail (right) holds the selected ride and the figures. Every figure comes
    from the controller's queries; nothing here is invented. The route line under the headline is drawn from real
    state: the five setup steps for an operator while bookings are closed, a plain pickup-to-drop-off line otherwise.
--}}
@php
    $roleHome = app(\App\Support\Ui\RoleHome::class);
    $role = $landing['role'];
    // The other spaces this person may enter (the primary one is the action above them).
    $otherSurfaces = array_values(array_filter($roleHome->surfaces(auth()->user()), fn (string $surface) => $surface !== $landing['surface']));
    $showSetupLine = $role === 'operator' && ! $booking->isOpen();
    $setupSteps = $showSetupLine ? $booking->steps() : [];
    $inPlace = count(array_filter($setupSteps, fn ($s) => $s['ready']));
    $fareMinor = fn ($amount) => (int) round(((float) $amount) * 100);
    $opsAccess = app(\App\Domain\Ops\Access\OpsAccess::class);
    $viewer = auth()->user();
    $isOperatorViewer = $viewer->isPlatformOperator();
    // A driver sees a rider's first name only (R-46 security S-08); the rider and an operator see the full name.
    $riderName = fn ($ride) => ($viewer->id === $ride->passenger_id || $isOperatorViewer)
        ? ($ride->passenger?->name ?? 'Not given')
        : \App\Domain\Safety\RideSafety::firstName($ride->passenger?->name, 'Not given');
    $otherPartyLabel = $role === 'driver' ? 'Passenger' : 'Driver';
    $otherParty = fn ($ride) => $role === 'driver' ? $riderName($ride) : ($ride->driver?->name ?? 'Not assigned');
    $ledgerTitle = match ($role) {
        'operator' => 'Rides on the platform',
        'driver' => 'Your trips',
        default => 'Your rides',
    };
    $showLedger = $role !== 'fleet';
    $routeStops = $showSetupLine
        ? array_map(fn ($s) => $s['ready'] ? 'done' : 'todo', $setupSteps)
        : ['stop', 'stop'];
@endphp
<x-app-layout>
<div class="eh-dash" data-landing-role="{{ $role }}" data-booking-open="{{ $booking->isOpen() ? 'yes' : 'no' }}">

    <section class="eh-dash__arrive" aria-labelledby="eh-dash-title">
        <div class="eh-dash__arrive-txt">
            <h1 class="eh-dash__title" id="eh-dash-title">{{ $landing['headline'] }}</h1>
            <p class="eh-dash__lede">{{ $landing['support'] }}</p>
            <p class="eh-dash__welcome" data-landing-welcome>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.@if ($companyName !== '') You are signed in for {{ $companyName }}.@endif</p>
            @if ($landing['notice'])
                <p class="eh-dash__notice" role="status" data-landing-notice>{{ $landing['notice'] }}</p>
            @endif
        </div>
        <div class="eh-dash__arrive-act">
            <x-eh.button :href="$landing['url']" :size="$role === 'driver' ? 'driver' : 'lg'" :icon="$landing['icon']" data-primary-action>{{ $landing['label'] }}</x-eh.button>
            @if (count($otherSurfaces))
                <nav aria-label="Your Dot.Ehail apps">
                    <ul class="eh-dash__spaces">
                        @foreach ($otherSurfaces as $surface)
                            <li><a href="{{ route(\App\Support\Ui\RoleHome::HOME_ROUTES[$surface]) }}">{{ \App\Support\Ui\RoleHome::LABELS[$surface] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
        {{-- The route line: decorative artwork drawn from the platform's own mark (stops joined by a line). --}}
        <div class="eh-dash__route" aria-hidden="true" style="--stops: {{ count($routeStops) }}">
            <span class="eh-dash__route-line"></span>
            @foreach ($routeStops as $i => $state)
                <span class="eh-dash__route-stop eh-dash__route-stop--{{ $state }}" style="--i: {{ $i }}"></span>
            @endforeach
        </div>
    </section>

    <div class="eh-dash__desk">
        <div class="eh-dash__main">

            @if ($activeTrip)
                {{-- The one live handoff: static, one tap to the live screen (never animated, never polled). --}}
                <section class="eh-dash__trip" aria-labelledby="eh-dash-trip" data-landing-trip>
                    <div>
                        <h2 id="eh-dash-trip">{{ $role === 'driver' ? 'A trip is in progress' : 'Your ride is in progress' }}</h2>
                        <p>{{ $activeTrip->pickup_address }} to {{ $activeTrip->dropoff_address }}</p>
                    </div>
                    <x-eh.status :status="$activeTrip->status" />
                </section>
            @endif

            @if ($showSetupLine)
                {{-- The setup line: the same five facts ehail:preflight reports under "Can a passenger book?". --}}
                <section class="eh-dash__block" aria-labelledby="eh-dash-setup">
                    <div class="eh-dash__block-head">
                        <h2 id="eh-dash-setup">Before passengers can book</h2>
                        <span class="eh-dash__state-word">{{ $inPlace }} of {{ count($setupSteps) }} in place</span>
                    </div>
                    <ol class="eh-dash__setup" aria-label="Setup steps" data-landing-setup>
                        @foreach ($setupSteps as $step)
                            <li data-state="{{ $step['ready'] ? 'done' : 'todo' }}" data-step="{{ $step['key'] }}">
                                <span class="eh-dash__stop" aria-hidden="true">@if ($step['ready'])<x-eh.icon name="check" />@endif</span>
                                <div>
                                    <b>{{ $step['label'] }}<span class="eh-sr-only">, {{ $step['ready'] ? 'in place' : 'missing' }}</span></b>
                                    @unless ($step['ready'])<small>{{ $step['missing'] }}</small>@endunless
                                </div>
                                @if ($step['ready'])
                                    <span class="eh-dash__state-word eh-dash__state-word--go">In place</span>
                                @elseif ($step['route'] && \Illuminate\Support\Facades\Route::has($step['route']) && $opsAccess->allowsRoute($viewer, $step['route']))
                                    <a class="eh-dash__link" href="{{ route($step['route']) }}">Open {{ $step['key'] === 'area' ? 'areas' : ($step['key'] === 'category' ? 'the catalogue' : 'tariffs') }}</a>
                                @else
                                    <span class="eh-dash__state-word">Host setting</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <p class="eh-dash__note">The full check, with every provider, is <code>php artisan ehail:preflight</code> on the host.</p>
                </section>
            @elseif (is_array($decisions))
                <section class="eh-dash__block" aria-labelledby="eh-dash-decide" data-landing-decisions>
                    <div class="eh-dash__block-head"><h2 id="eh-dash-decide">Needs a decision</h2></div>
                    @php
                        $queue = [
                            'needs_review' => ['Rides to review', 'ops.live'],
                            'stuck' => ['Rides stuck', 'ops.live'],
                            'searching' => ['Waiting for a driver', 'ops.live'],
                            'incidents' => ['Open safety incidents', 'ops.safety'],
                        ];
                        $open = array_sum(array_intersect_key($decisions, array_flip(['needs_review', 'stuck', 'incidents'])));
                    @endphp
                    @if ($open === 0)
                        <p class="eh-dash__calm">Nothing needs a decision right now.</p>
                    @endif
                    <ul class="eh-dash__rows">
                        @foreach ($queue as $key => [$label, $routeName])
                            @continue(! array_key_exists($key, $decisions))
                            <li>
                                @if (\Illuminate\Support\Facades\Route::has($routeName) && $opsAccess->allowsRoute($viewer, $routeName))
                                    <a class="eh-dash__row-link" href="{{ route($routeName) }}">{{ $label }}</a>
                                @else
                                    <span>{{ $label }}</span>
                                @endif
                                <span class="eh-dash__val{{ (int) $decisions[$key] === 0 ? ' eh-dash__val--zero' : '' }}" data-count="{{ (int) $decisions[$key] }}">{{ (int) $decisions[$key] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($role === 'fleet' && $fleetDigest)
                <section class="eh-dash__block" aria-labelledby="eh-dash-fleet-today" data-landing-fleet-digest>
                    <div class="eh-dash__block-head"><h2 id="eh-dash-fleet-today">{{ $fleetDigest['fleet'] }} today</h2><a class="eh-dash__link" href="{{ route('fleet.home') }}">Open your fleet</a></div>
                    <ul class="eh-dash__rows">
                        @foreach ([['Vehicles in service', $fleetDigest['vehicles']], ['Drivers online now', $fleetDigest['online']], ['Driver applications waiting', $fleetDigest['applications']], ['Alerts that need action', $fleetDigest['alerts']]] as [$label, $value])
                            <li><span>{{ $label }}</span><span class="eh-dash__val{{ $value === 0 ? ' eh-dash__val--zero' : '' }}" data-count="{{ $value }}">{{ $value }}</span></li>
                        @endforeach
                    </ul>
                    <p class="eh-dash__note">Trips run in the fleet portal; this page shows counts only.</p>
                </section>
            @endif

            @if ($role === 'driver' && $driverToday)
                <section class="eh-dash__block" aria-labelledby="eh-dash-today" data-landing-driver-today>
                    <div class="eh-dash__block-head"><h2 id="eh-dash-today">Today</h2></div>
                    <dl class="eh-dash__facts">
                        <dt>Net earned</dt><dd class="eh-dash__big"><x-eh.money :minor="$driverToday['net_minor']" /></dd>
                        <dt>Trips</dt><dd data-count="{{ $driverToday['trips'] }}">{{ $driverToday['trips'] }}</dd>
                        <dt>You are</dt><dd>{{ ! $driverToday['approved'] ? 'Not approved yet' : ($driverToday['online'] ? 'Online' : 'Offline') }}</dd>
                        @if ($driverToday['plate'])<dt>Vehicle</dt><dd>{{ $driverToday['plate'] }}</dd>@endif
                    </dl>
                    @if ($driverToday['trips'] === 0)<p class="eh-dash__note">No trips yet today.</p>@endif
                </section>
            @endif

            @if ($showLedger)
                <section class="eh-dash__block" aria-labelledby="eh-dash-ledger">
                    <div class="eh-dash__block-head">
                        <h2 id="eh-dash-ledger">{{ $ledgerTitle }}</h2>
                        @if ($totalRides > 0)<a class="eh-dash__link" href="{{ route('rides.index') }}">All rides</a>@endif
                    </div>
                    @if ($recentRides->isEmpty())
                        <x-eh.empty title="No rides recorded yet." :pending="! $booking->isOpen()" :heading-level="3" data-landing-empty>
                            {{ $role === 'operator' ? 'The' : 'Your' }} first ride will appear here with its route, its status and its fare.
                        </x-eh.empty>
                    @else
                        <div class="table-scroll" role="region" aria-label="Recent rides" tabindex="0">
                        <table class="eh-dash__ledger" data-landing-ledger>
                            <caption class="eh-sr-only">Recent rides</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Trip</th>
                                    <th scope="col" class="eh-dash__hide-md">{{ $otherPartyLabel }}</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="eh-dash__num">Fare</th>
                                    <th scope="col" class="eh-dash__num">Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentRides as $ride)
                                    @php($isSel = $selected && $selected->is($ride))
                                    <tr @if ($isSel) aria-selected="true" @endif style="--i: {{ $loop->index }}">
                                        <td>
                                            <a href="{{ route('dashboard', ['ride' => $ride->id]) }}" @if ($isSel) aria-current="true" @endif>{{ $ride->pickup_address }}</a>
                                            <span class="eh-dash__to">to {{ $ride->dropoff_address }}</span>
                                            <span class="eh-dash__to eh-dash__m-only">@if ($ride->final_fare)<x-eh.money :minor="$fareMinor($ride->final_fare)" />@elseif ($ride->estimated_fare)Estimate <x-eh.money :minor="$fareMinor($ride->estimated_fare)" muted />@else No fare @endif, {{ $ride->created_at->diffForHumans() }}</span>
                                        </td>
                                        <td class="eh-dash__hide-md">{{ $otherParty($ride) }}</td>
                                        <td><x-eh.status :status="$ride->status" /></td>
                                        <td class="eh-dash__num">
                                            @if ($ride->final_fare)
                                                <x-eh.money :minor="$fareMinor($ride->final_fare)" />
                                            @elseif ($ride->estimated_fare)
                                                <span class="eh-muted">Estimate</span> <x-eh.money :minor="$fareMinor($ride->estimated_fare)" muted />
                                            @else
                                                <span class="eh-muted">None</span>
                                            @endif
                                        </td>
                                        <td class="eh-dash__num">{{ $ride->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <aside class="eh-dash__detail" aria-label="Selected ride and figures">
            @if ($showLedger && $selected)
                <section class="eh-dash__block eh-dash__panel" aria-labelledby="eh-dash-sel" data-landing-latest>
                    <h2 id="eh-dash-sel">{{ $selected->is($recentRides->first()) && ! request()->filled('ride') ? 'Latest ride' : 'Selected ride' }}</h2>
                    <ol class="eh-dash__stops" aria-label="Route of the ride">
                        <li><b>{{ $selected->pickup_address }}</b><small>Pickup</small></li>
                        <li><b>{{ $selected->dropoff_address }}</b><small>Drop-off</small></li>
                    </ol>
                    <dl class="eh-dash__facts">
                        <dt>Status</dt><dd><x-eh.status :status="$selected->status" /></dd>
                        <dt>Fare</dt>
                        <dd class="eh-dash__big">
                            @if ($selected->final_fare)<x-eh.money :minor="$fareMinor($selected->final_fare)" />
                            @elseif ($selected->estimated_fare)<span class="eh-dash__est">Estimate</span> <x-eh.money :minor="$fareMinor($selected->estimated_fare)" />
                            @else<span class="eh-muted">None</span>@endif
                        </dd>
                        <dt>Driver</dt><dd>{{ $selected->driver?->name ?? 'Not assigned' }}</dd>
                        <dt>Passenger</dt><dd>{{ $riderName($selected) }}</dd>
                        <dt>Requested</dt><dd>{{ $selected->created_at->diffForHumans() }}</dd>
                    </dl>
                    <p class="eh-dash__after"><x-eh.button :href="route('rides.show', $selected)" variant="secondary">Open this ride</x-eh.button></p>
                </section>
            @endif

            @if ($totalRides > 0 || $showFleetStats)
                <section class="eh-dash__block" aria-labelledby="eh-dash-figs">
                    <h2 id="eh-dash-figs">{{ $role === 'fleet' ? 'Your fleet figures' : 'Figures' }}</h2>
                    @if ($totalRides > 0)
                        <dl class="eh-dash__facts" data-landing-figures aria-label="Ride figures">
                            @foreach ([['Rides in total', $totalRides], ['On the road now', $activeRides], ['Waiting for a driver', $requestedRides], ['Completed', $completedRides], ['Cancelled', $cancelledRides]] as [$label, $value])
                                <dt>{{ $label }}</dt><dd data-count="{{ $value }}">{{ number_format($value) }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    @if ($showFleetStats)
                        <dl class="eh-dash__facts" data-landing-fleet-figures aria-label="Driver and fare figures">
                            <dt>Drivers</dt><dd data-count="{{ $totalDrivers }}">{{ number_format($totalDrivers) }}</dd>
                            <dt>Approved</dt><dd data-count="{{ $approvedDrivers }}">{{ number_format($approvedDrivers) }}</dd>
                            <dt>Online now</dt><dd data-count="{{ $availableDrivers }}">{{ number_format($availableDrivers) }}</dd>
                            <dt>In completed fares</dt><dd>{{ \App\Support\Ui\MoneyFormatter::format($fareMinor($totalRevenue)) }}</dd>
                        </dl>
                        <p class="eh-dash__note">Estimated fare total, not collected: the sum of the final fares on completed rides, before commission and payouts.</p>
                    @endif
                </section>
            @elseif ($role === 'driver')
                <section class="eh-dash__block" aria-labelledby="eh-dash-pay">
                    <h2 id="eh-dash-pay">Earnings and payouts</h2>
                    <p class="eh-dash__note">Your statement shows the commission line by line.</p>
                    <p class="eh-dash__after"><x-eh.button :href="route('drive.home')" variant="secondary">Open your driver console</x-eh.button></p>
                </section>
            @endif
        </aside>
    </div>

    <footer class="eh-dash__foot">
        <span>{{ number_format($totalRides) }} {{ $totalRides === 1 ? 'ride' : 'rides' }}@if ($showFleetStats), {{ number_format($totalDrivers) }} {{ $totalDrivers === 1 ? 'driver' : 'drivers' }}, {{ number_format($availableDrivers) }} online now @endif</span>
        @if (! \App\Support\Ui\AppsSwitcher::model($viewer, 'dashboard'))
            <a href="{{ $infodotUrl }}/home">InfoDot</a>
        @endif
    </footer>
</div>
</x-app-layout>
