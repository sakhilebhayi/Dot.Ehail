{{--
    The signed-in landing (R-50): where a person arrives from InfoDot. Route Line tokens only (resources/css/ds/home.css).
    One dominant action per role (App\Support\Ui\LandingAction), marked data-primary-action; everything else is a text
    link, a figure in a sentence row or a table. The logo is the original file, never the redrawn mark. Every figure
    comes from the controller's queries; nothing here is invented.
--}}
@php
    $roleHome = app(\App\Support\Ui\RoleHome::class);
    // The other spaces this person may enter (the primary one is the action above them).
    $otherSurfaces = array_values(array_filter($roleHome->surfaces(auth()->user()), fn (string $surface) => $surface !== $landing['surface']));
    $latestRide = $recentRides->first();
    $showSetupLine = $landing['role'] === 'operator' && ! $booking->isOpen();
    $fareMinor = fn ($amount) => (int) round(((float) $amount) * 100);
    $opsAccess = app(\App\Domain\Ops\Access\OpsAccess::class);
@endphp
<x-app-layout>
<div class="eh-home" data-landing-role="{{ $landing['role'] }}" data-booking-open="{{ $booking->isOpen() ? 'yes' : 'no' }}">

    <header class="eh-home__brand">
        <img class="eh-home__logo eh-home__logo--day" src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" width="1181" height="886" data-landing-logo>
        <img class="eh-home__logo eh-home__logo--night" src="{{ asset('images/logo-light.png') }}" alt="" aria-hidden="true" width="1181" height="886">
        <nav aria-label="Dot ecosystem">
            <ul class="eh-home__back">
                <li><a href="{{ $infodotUrl }}/home">InfoDot</a></li>
                <li><a href="{{ $infodotUrl }}/platforms">Platforms</a></li>
            </ul>
        </nav>
    </header>

    <div class="eh-home__lead">
        <section aria-labelledby="eh-home-title">
            <div class="eh-home__enter">
                <h1 class="eh-display eh-home__title" id="eh-home-title">{{ $landing['headline'] }}</h1>
                <p class="eh-home__welcome" data-landing-welcome>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.@if ($companyName !== '') You are signed in for {{ $companyName }}.@endif</p>
                <p class="eh-home__welcome">{{ $landing['support'] }}</p>
                @if ($landing['notice'])
                    <p class="eh-home__notice" role="status" data-landing-notice>{{ $landing['notice'] }}</p>
                @endif
            </div>

            <div class="eh-home__act eh-home__enter eh-home__enter--late">
                <x-eh.button :href="$landing['url']" size="lg" :icon="$landing['icon']" data-primary-action>{{ $landing['label'] }}</x-eh.button>
                @if (count($otherSurfaces))
                    <nav aria-label="Your Dot.Ehail apps">
                        <ul class="eh-home__more">
                            @foreach ($otherSurfaces as $surface)
                                <li><a href="{{ route(\App\Support\Ui\RoleHome::HOME_ROUTES[$surface]) }}">{{ \App\Support\Ui\RoleHome::LABELS[$surface] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </div>
        </section>

        <section class="eh-home__route" aria-labelledby="eh-home-route">
            @if ($showSetupLine)
                {{-- The setup line: the same five facts ehail:preflight reports under "Can a passenger book?". --}}
                <h2 class="eh-h2" id="eh-home-route">Before passengers can book</h2>
                <ol class="eh-stepper" aria-label="Setup steps" data-landing-setup>
                    @foreach ($booking->steps() as $step)
                        <li class="eh-stepper__step" data-state="{{ $step['ready'] ? 'done' : 'todo' }}" data-step="{{ $step['key'] }}">
                            <span class="eh-stepper__dot">@if ($step['ready'])<x-eh.icon name="check" />@endif</span>
                            <span class="eh-stepper__body">
                                <span class="eh-stepper__label">{{ $step['label'] }}<span class="eh-sr-only">, {{ $step['ready'] ? 'in place' : 'missing' }}</span></span>
                                @if ($step['ready'])
                                    <span class="eh-stepper__meta" style="display:block">In place</span>
                                @else
                                    <span class="eh-stepper__meta" style="display:block">{{ $step['missing'] }}
                                        @if ($step['route'] && \Illuminate\Support\Facades\Route::has($step['route']) && $opsAccess->allowsRoute(auth()->user(), $step['route']))
                                            <a class="eh-link" href="{{ route($step['route']) }}">Open {{ $step['key'] === 'area' ? 'areas' : ($step['key'] === 'category' ? 'the catalogue' : 'tariffs') }}</a>
                                        @endif
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
                <p class="eh-home__note">The full check, with every provider, is <code>php artisan ehail:preflight</code> on the host.</p>
            @elseif ($latestRide)
                <h2 class="eh-h2" id="eh-home-route">Latest ride</h2>
                <ol class="eh-stepper" aria-label="Route of the latest ride" data-landing-latest>
                    <li class="eh-stepper__step" data-state="stop">
                        <span class="eh-stepper__dot"></span>
                        <span class="eh-stepper__body"><span class="eh-stepper__label">{{ $latestRide->pickup_address }}</span><span class="eh-stepper__meta" style="display:block">Pickup</span></span>
                    </li>
                    <li class="eh-stepper__step" data-state="stop">
                        <span class="eh-stepper__dot"></span>
                        <span class="eh-stepper__body"><span class="eh-stepper__label">{{ $latestRide->dropoff_address }}</span><span class="eh-stepper__meta" style="display:block">Drop-off</span></span>
                    </li>
                </ol>
                <div class="eh-home__route-foot">
                    <x-eh.status :status="$latestRide->status" />
                    @if ($latestRide->final_fare)
                        <x-eh.money :minor="$fareMinor($latestRide->final_fare)" />
                    @elseif ($latestRide->estimated_fare)
                        <span>Estimate <x-eh.money :minor="$fareMinor($latestRide->estimated_fare)" /></span>
                    @endif
                    <span>{{ $latestRide->created_at->diffForHumans() }}</span>
                    <a class="eh-link" href="{{ route('rides.show', $latestRide) }}">Open this ride</a>
                </div>
            @else
                <h2 class="eh-sr-only" id="eh-home-route">Rides</h2>
                <x-eh.empty title="No rides recorded yet." :pending="! $booking->isOpen()" :heading-level="3" data-landing-empty>
                    {{ $landing['role'] === 'operator' ? 'The' : 'Your' }} first ride will appear here with its route, its status and its fare.
                </x-eh.empty>
            @endif
        </section>
    </div>

    @if ($totalRides > 0 || $showFleetStats)
    <div class="eh-home__context">
        @if ($totalRides > 0)
        <section class="eh-home__block" aria-labelledby="eh-home-rides">
            <h2 class="eh-h2" id="eh-home-rides">{{ $landing['role'] === 'operator' ? 'Rides on the platform' : 'Your rides' }}</h2>
            <x-eh.stat-line label="Ride figures" data-landing-figures :items="[
                ['value' => number_format($totalRides), 'label' => 'in total'],
                ['value' => number_format($activeRides), 'label' => 'on the road now'],
                ['value' => number_format($requestedRides), 'label' => 'waiting for a driver'],
                ['value' => number_format($completedRides), 'label' => 'completed'],
                ['value' => number_format($cancelledRides), 'label' => 'cancelled'],
            ]" />
        </section>
        @endif

        @if ($showFleetStats)
        <section class="eh-home__block" aria-labelledby="eh-home-fleet">
            <h2 class="eh-h2" id="eh-home-fleet">{{ $landing['role'] === 'operator' ? 'Drivers and fares' : 'Your fleet' }}</h2>
            <x-eh.stat-line label="Driver and fare figures" data-landing-fleet-figures :items="[
                ['value' => number_format($totalDrivers), 'label' => 'drivers'],
                ['value' => number_format($approvedDrivers), 'label' => 'approved'],
                ['value' => number_format($availableDrivers), 'label' => 'online now'],
                ['value' => \App\Support\Ui\MoneyFormatter::format($fareMinor($totalRevenue)), 'label' => 'in completed fares'],
            ]" />
            <p class="eh-home__note">Estimated fare total, not collected: the sum of the final fares on completed rides, before commission and payouts.</p>
        </section>
        @endif

        @if ($totalRides > 0)
        <section class="eh-home__block" aria-labelledby="eh-home-recent">
            <h2 class="eh-h2" id="eh-home-recent">Recent rides</h2>
            <div class="table-scroll" role="region" aria-label="Recent rides" tabindex="0">
                <table class="eh-home__table">
                    <thead>
                        <tr>
                            <th scope="col">Route</th>
                            <th scope="col">Driver</th>
                            <th scope="col">Passenger</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="eh-home__num">Fare</th>
                            <th scope="col" class="eh-home__num">Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentRides as $ride)
                        <tr>
                            <td>
                                <a href="{{ route('rides.show', $ride) }}">{{ $ride->pickup_address }}</a>
                                <span class="eh-home__to">to {{ $ride->dropoff_address }}</span>
                            </td>
                            <td>{{ $ride->driver?->name ?? 'Not assigned' }}</td>
                            {{-- A driver sees a rider's first name only (R-46 security S-08). --}}
                            <td>{{ (auth()->id() === $ride->passenger_id || auth()->user()->isPlatformOperator()) ? ($ride->passenger?->name ?? 'Not given') : \App\Domain\Safety\RideSafety::firstName($ride->passenger?->name, 'Not given') }}</td>
                            <td><x-eh.status :status="$ride->status" /></td>
                            <td class="eh-home__num">
                                @if ($ride->final_fare)
                                    <x-eh.money :minor="$fareMinor($ride->final_fare)" />
                                @elseif ($ride->estimated_fare)
                                    <span class="eh-muted">Estimate</span> <x-eh.money :minor="$fareMinor($ride->estimated_fare)" muted />
                                @else
                                    <span class="eh-muted">None</span>
                                @endif
                            </td>
                            <td class="eh-home__num">{{ $ride->created_at->diffForHumans() }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="eh-home__note"><a class="eh-link" href="{{ route('rides.index') }}">All rides</a></p>
        </section>
        @endif
    </div>
    @endif

</div>
</x-app-layout>
