<x-business-layout title="Business" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php $cur = $account->currency; @endphp
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">{{ $account->displayName() }}</h1>
                @if ($can['view_overview'])
                    <p class="bz-title__sub">{{ $monthLabel }} so far: {{ \App\Support\Ui\MoneyFormatter::format($spent, $cur) }} billed on {{ $rideCount }} {{ \Illuminate\Support\Str::plural('ride', $rideCount) }}{{ $pendingCount > 0 ? ', and '.$pendingCount.' '.\Illuminate\Support\Str::plural('ride', $pendingCount).' waiting for your approval' : '' }}.</p>
                @else
                    <p class="bz-title__sub">You ride on this account as {{ strtolower($member->roleLabel()) }}. Choose the business profile when you request a ride and the trip is billed to {{ $account->displayName() }}.</p>
                @endif
            </div>
            <div class="bz-title__actions">
                @if ($memberships->count() > 1)
                    <form class="bz-switch" method="POST" action="{{ route('business.switch') }}">
                        @csrf
                        <label class="eh-sr-only" for="bz-account">Business account</label>
                        <div class="eh-select-wrap"><select class="eh-select" id="bz-account" name="account_id" onchange="this.form.submit()">
                            @foreach ($memberships as $m)<option value="{{ $m->business_account_id }}" @selected($m->business_account_id === $account->id)>{{ $m->account->displayName() }}</option>@endforeach
                        </select><x-eh.icon name="chevron-down" /></div>
                        <noscript><x-eh.button type="submit" variant="secondary" size="sm">Switch</x-eh.button></noscript>
                    </form>
                @endif
                @if ($can['manage_people'])<x-eh.button variant="secondary" icon="plus" :href="route('business.people').'#invite'">Invite someone</x-eh.button>@endif
                @if ($can['ride'])<x-eh.button icon="car" :href="route('business.book')">{{ $can['book_for_others'] ? 'Book a ride' : 'Book a business ride' }}</x-eh.button>@endif
            </div>
        </div>

        @include('business.partials.state')

        @foreach ($invitations as $invitation)
            <x-eh.banner tone="info" title="{{ $invitation->account->displayName() }} invited you">
                Join as {{ strtolower($invitation->roleLabel()) }}.
                <x-slot:actions>
                    <form method="POST" action="{{ route('business.invitations.accept-listed', $invitation->id) }}" style="margin:0">@csrf<x-eh.button type="submit" size="sm">Accept</x-eh.button></form>
                </x-slot:actions>
            </x-eh.banner>
        @endforeach

        @if ($can['view_overview'])
            <div class="bz-grid">
                <div class="bz" style="gap:var(--eh-space-6)">
                    <section class="bz-panel bz-month" aria-labelledby="bz-month-title">
                        <div class="bz-panel__head">
                            <h2 class="bz-panel__title" id="bz-month-title">Spend this month</h2>
                            <p class="bz-panel__caption">{{ $monthLabel }}, counted in South African time</p>
                        </div>
                        @php
                            $base = $totalBudget > 0 ? $totalBudget : max(1, (int) $account->credit_limit_minor);
                            $pct = fn (int $v) => max(0, min(100, round($v * 100 / max(1, $base), 2)));
                            $over = $totalBudget > 0 && $spent > $totalBudget;
                        @endphp
                        <p class="bz-month__figure">
                            <span class="bz-month__amount"><x-eh.money :minor="$spent" :currency="$cur" /></span>
                            <span class="bz-month__of">
                                @if ($totalBudget > 0) of <x-eh.money :minor="$totalBudget" :currency="$cur" /> in department budgets
                                @else billed; no department budgets are set @endif
                            </span>
                        </p>
                        <p class="bz-month__finding">
                            @if ($totalBudget > 0)
                                @if ($over) Billed rides are {{ \App\Support\Ui\MoneyFormatter::format($spent - $totalBudget, $cur) }} over the combined budgets.
                                @else {{ \App\Support\Ui\MoneyFormatter::format(max(0, $totalBudget - $spent - $held), $cur) }} of the budgets is still free after {{ \App\Support\Ui\MoneyFormatter::format($held, $cur) }} held for booked rides. @endif
                            @else
                                The line below is measured against the credit limit of {{ \App\Support\Ui\MoneyFormatter::format((int) $account->credit_limit_minor, $cur) }}.
                            @endif
                        </p>
                        <div class="bz-line {{ $over ? 'bz-line--over' : '' }}" role="img" aria-label="{{ \App\Support\Ui\MoneyFormatter::spoken($spent, $cur) }} billed and {{ \App\Support\Ui\MoneyFormatter::spoken($held, $cur) }} held, of {{ \App\Support\Ui\MoneyFormatter::spoken($base, $cur) }}">
                            <span class="bz-line__spent" style="width: {{ $pct($spent) }}%"></span>
                            <span class="bz-line__held" style="width: {{ $pct($held) }}%"></span>
                        </div>
                        <ul class="bz-stops">
                            <li><i class="bz-stop bz-stop--spent" aria-hidden="true"></i>Billed <strong>{{ \App\Support\Ui\MoneyFormatter::format($spent, $cur) }}</strong></li>
                            <li><i class="bz-stop bz-stop--held" aria-hidden="true"></i>Held for booked rides <strong>{{ \App\Support\Ui\MoneyFormatter::format($held, $cur) }}</strong></li>
                            <li><i class="bz-stop" aria-hidden="true"></i>{{ $totalBudget > 0 ? 'Budgets' : 'Credit limit' }} <strong>{{ \App\Support\Ui\MoneyFormatter::format($base, $cur) }}</strong></li>
                        </ul>

                        @if ($budgets->isNotEmpty())
                            <ul class="bz-budgets" aria-label="Department budgets this month">
                                @foreach ($budgets as $b)
                                    @php $bpct = fn (int $v) => max(0, min(100, round($v * 100 / max(1, $b['budget']), 2))); @endphp
                                    <li class="bz-budget">
                                        <span class="bz-budget__name">{{ $b['department']->name }}@if ($b['department']->cost_centre)<small>{{ $b['department']->cost_centre }}</small>@endif</span>
                                        <span class="bz-line bz-line--thin {{ $b['spent'] > $b['budget'] ? 'bz-line--over' : '' }}" role="img" aria-label="{{ \App\Support\Ui\MoneyFormatter::spoken($b['spent'], $cur) }} of {{ \App\Support\Ui\MoneyFormatter::spoken($b['budget'], $cur) }}">
                                            <span class="bz-line__spent" style="width: {{ $bpct($b['spent']) }}%"></span>
                                            <span class="bz-line__held" style="width: {{ $bpct($b['held']) }}%"></span>
                                        </span>
                                        <span class="bz-budget__fig"><strong>{{ \App\Support\Ui\MoneyFormatter::format($b['spent'], $cur) }}</strong> of {{ \App\Support\Ui\MoneyFormatter::format($b['budget'], $cur) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($can['manage_departments'])
                            <p class="bz-hint" style="margin-top:var(--eh-space-5)">Give departments a monthly budget on the <a class="eh-link" href="{{ route('business.people') }}#departments">People</a> page and each one gets its own line here.</p>
                        @endif
                    </section>

                    <section class="bz-panel bz-panel--flush" aria-labelledby="bz-recent-title">
                        <div class="bz-panel__head">
                            <h2 class="bz-panel__title" id="bz-recent-title">Latest trips</h2>
                            <a class="eh-link" href="{{ route('business.trips') }}">All trips</a>
                        </div>
                        @if ($recentTrips->isEmpty())
                            <div style="padding:0 var(--eh-space-5) var(--eh-space-5)">
                                <p class="bz-hint">No rides yet. When someone requests a ride with the {{ $account->displayName() }} business profile, it shows here with its purpose, cost centre and fare.</p>
                            </div>
                        @else
                            <div class="bz-tablewrap">
                                <table class="bz-table bz-table--stack">
                                    <thead><tr><th scope="col">When</th><th scope="col">Rider</th><th scope="col">Purpose</th><th scope="col">Ride</th><th scope="col" class="num">Amount</th></tr></thead>
                                    <tbody>@foreach ($recentTrips as $trip)@include('business.partials.trip-row', ['trip' => $trip, 'showDepartment' => false, 'showRoute' => false])@endforeach</tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="bz">
                    @if ($can['decide_approvals'])
                        <section class="bz-panel {{ $pendingCount > 0 ? 'bz-first-on-phone' : '' }}" aria-labelledby="bz-queue-title">
                            <div class="bz-panel__head">
                                <h2 class="bz-panel__title" id="bz-queue-title">Waiting for approval</h2>
                                @if ($pendingCount > 0)<a class="eh-link" href="{{ route('business.approvals') }}">All {{ $pendingCount }}</a>@endif
                            </div>
                            @if ($pendingApprovals->isEmpty())
                                <p class="bz-hint">Nothing is waiting. Rides above a policy's approval amount land here, and lapse if nobody decides in time.</p>
                            @else
                                @include('business.partials.queue', ['items' => $pendingApprovals])
                            @endif
                        </section>
                    @endif

                    <section class="bz-panel" aria-labelledby="bz-credit-title">
                        <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-credit-title">Credit with Dot.Ehail</h2></div>
                        <dl class="bz-facts">
                            <div><dt>Owed now</dt><dd><x-eh.money :minor="$outstanding" :currency="$cur" /></dd></div>
                            <div><dt>Held for booked rides</dt><dd><x-eh.money :minor="$held" :currency="$cur" /></dd></div>
                            <div><dt>Available for new rides</dt><dd><x-eh.money :minor="max(0, $creditAvailable)" :currency="$cur" /></dd></div>
                            <div><dt>Credit limit</dt><dd><x-eh.money :minor="(int) $account->credit_limit_minor" :currency="$cur" /></dd></div>
                            <div><dt>Pays by</dt><dd>{{ $account->billing_mode === 'card' ? 'Company card, monthly' : 'EFT, '.$account->terms_days.' days after each statement' }}</dd></div>
                        </dl>
                        @if ($latestStatement)
                            <div class="bz-panel__foot">
                                <x-eh.badge :tone="$latestStatement->statusTone()">{{ $latestStatement->statusLabel() }}</x-eh.badge>
                                <a class="eh-link" href="{{ route('business.statements.show', $latestStatement) }}">Statement {{ $latestStatement->number }}</a>
                            </div>
                        @endif
                    </section>

                    <section class="bz-panel" aria-labelledby="bz-people-title">
                        <div class="bz-panel__head">
                            <h2 class="bz-panel__title" id="bz-people-title">People</h2>
                            <a class="eh-link" href="{{ route('business.people') }}">Manage</a>
                        </div>
                        <dl class="bz-facts">
                            <div><dt>On the account</dt><dd>{{ $peopleCount }}</dd></div>
                            <div><dt>Invitations not yet accepted</dt><dd>{{ $invitedCount }}</dd></div>
                        </dl>
                    </section>
                </div>
            </div>
        @else
            @include('business.partials.traveller')
        @endif
    </div>
</x-business-layout>
