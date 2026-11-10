{{-- What a traveller sees: their allowance, their policy in plain words, their requests and their trips. --}}
@php($cur = $account->currency)
<div class="bz-grid">
    <div class="bz">
        @if ($member->monthly_allowance_minor !== null)
            @php($left = max(0, $member->monthly_allowance_minor - $allowanceUsed))
            <section class="bz-panel" aria-labelledby="bz-allow-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-allow-title">Your ride allowance</h2><p class="bz-panel__caption">{{ $monthLabel }}</p></div>
                <p class="bz-month__figure"><span class="bz-month__amount"><x-eh.money :minor="$left" :currency="$cur" /></span><span class="bz-month__of">left of <x-eh.money :minor="$member->monthly_allowance_minor" :currency="$cur" /></span></p>
                <div class="bz-line" role="img" aria-label="{{ \App\Support\Ui\MoneyFormatter::spoken($allowanceUsed, $cur) }} used">
                    <span class="bz-line__spent" style="width: {{ max(0, min(100, round($allowanceUsed * 100 / max(1, $member->monthly_allowance_minor), 2))) }}%"></span>
                </div>
                <ul class="bz-stops"><li><i class="bz-stop bz-stop--spent" aria-hidden="true"></i>Used, including rides booked <strong>{{ \App\Support\Ui\MoneyFormatter::format($allowanceUsed, $cur) }}</strong></li></ul>
            </section>
        @endif

        <section class="bz-panel bz-panel--flush" aria-labelledby="bz-mytrips-title">
            <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-mytrips-title">Your business trips</h2><a class="eh-link" href="{{ route('business.trips') }}">All</a></div>
            @if ($myTrips->isEmpty())
                <div style="padding:0 var(--eh-space-5) var(--eh-space-5)"><p class="bz-hint">None yet. Request a ride and choose the {{ $account->displayName() }} business profile.</p></div>
            @else
                <div class="bz-tablewrap"><table class="bz-table">
                    <thead><tr><th scope="col">When</th><th scope="col">Rider</th><th scope="col">Purpose</th><th scope="col">Route</th><th scope="col">Ride</th><th scope="col" class="num">Amount</th></tr></thead>
                    <tbody>@foreach ($myTrips as $trip)@include('business.partials.trip-row', ['trip' => $trip, 'showDepartment' => false])@endforeach</tbody>
                </table></div>
            @endif
        </section>
    </div>
    <div class="bz">
        <section class="bz-panel" aria-labelledby="bz-policy-title">
            <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-policy-title">Your ride policy</h2>@if ($policy)<p class="bz-panel__caption">{{ $policy->name }}</p>@endif</div>
            @include('business.partials.policy-facts', ['policy' => $policy, 'cur' => $cur])
        </section>
        <section class="bz-panel" aria-labelledby="bz-myreq-title">
            <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-myreq-title">Your approval requests</h2><a class="eh-link" href="{{ route('business.approvals') }}">All</a></div>
            @if ($myApprovals->isEmpty())
                <p class="bz-hint">None. If a ride needs approval, the app tells you before you request it and lets you ask.</p>
            @else
                <ul class="bz-queue">
                    @foreach ($myApprovals as $a)
                        <li><div class="bz-queue__top"><div class="bz-queue__who"><p class="bz-queue__name">{{ $a->dropoff_address ?? 'Ride' }}</p><p class="bz-queue__meta">{{ $a->created_at->setTimezone(config('ehail_business.timezone'))->format('j M H:i') }}</p></div><div class="bz-queue__amount"><x-eh.money :minor="$a->estimate_minor" :currency="$a->currency" /><br><x-eh.badge :tone="$a->statusTone()">{{ $a->statusLabel() }}</x-eh.badge></div></div></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
