<x-business-layout title="Approvals" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php($tz = \App\Domain\Business\BusinessClock::timezone())
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">Approvals</h1>
                <p class="bz-title__sub">
                    @if ($can['decide_approvals'])
                        {{ $queue->count() }} {{ \Illuminate\Support\Str::plural('ride', $queue->count()) }} waiting for you. An approved ride must be booked within {{ (int) config('ehail_business.approvals.use_within_minutes') }} minutes and only for the same trip.
                    @else
                        Rides you asked an approver about. Once approved, request the same trip again with your business profile.
                    @endif
                </p>
            </div>
        </div>
        @include('business.partials.state')

        <div class="bz-grid">
            <div class="bz">
                @if ($can['decide_approvals'])
                    <section class="bz-panel" aria-labelledby="bz-q-title">
                        <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-q-title">Waiting for a decision</h2><p class="bz-panel__caption">Oldest deadline first</p></div>
                        @if ($queue->isEmpty())
                            <p class="bz-hint">Nothing is waiting for you.</p>
                        @else
                            @include('business.partials.queue', ['items' => $queue])
                        @endif
                    </section>
                @endif

                <section class="bz-panel bz-panel--flush" aria-labelledby="bz-h-title">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-h-title">Decided and lapsed</h2></div>
                    @if ($history->isEmpty())
                        <div style="padding:0 var(--eh-space-5) var(--eh-space-5)"><p class="bz-hint">No decisions yet.</p></div>
                    @else
                        <div class="bz-tablewrap"><table class="bz-table bz-table--stack">
                            <thead><tr><th scope="col">Asked</th><th scope="col">Rider</th><th scope="col">Trip</th><th scope="col">Outcome</th><th scope="col" class="num">Fare asked</th></tr></thead>
                            <tbody>
                                @foreach ($history as $a)
                                    <tr>
                                        <td data-label="Asked">{{ $a->created_at->setTimezone($tz)->format('j M, H:i') }}</td>
                                        <td data-label="Rider"><span>{{ $a->riderLabel() }}<span class="bz-table__sub">{{ $a->purpose ?: 'No purpose given' }}</span></span></td>
                                        <td data-label="Trip"><div class="bz-route"><span><i class="bz-stop bz-stop--pickup" aria-hidden="true"></i>{{ \Illuminate\Support\Str::limit(\App\Support\Ui\PlaceNames::for($a->pickup_address, $a->pickup_lat, $a->pickup_lng), 48) }}</span><span><i class="bz-stop bz-stop--dropoff" aria-hidden="true"></i>{{ \Illuminate\Support\Str::limit(\App\Support\Ui\PlaceNames::for($a->dropoff_address, $a->dropoff_lat, $a->dropoff_lng), 48) }}</span></div></td>
                                        <td data-label="Outcome"><span><x-eh.badge :tone="$a->statusTone()">{{ $a->statusLabel() }}</x-eh.badge>@if ($a->decision_note)<span class="bz-table__sub">{{ $a->decision_note }}</span>@endif</span></td>
                                        <td class="num" data-label="Fare asked"><x-eh.money :minor="$a->estimate_minor" :currency="$a->currency" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>
            </div>

            <section class="bz-panel" aria-labelledby="bz-mine-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-mine-title">Your open requests</h2></div>
                @if ($mine->isEmpty())
                    <p class="bz-hint">You have no request waiting. When a ride needs approval, the booking screen offers to ask.</p>
                @else
                    <ul class="bz-queue">
                        @foreach ($mine as $a)
                            <li>
                                <div class="bz-queue__top">
                                    <div class="bz-queue__who"><p class="bz-queue__name">To {{ \App\Support\Ui\PlaceNames::for($a->dropoff_address, $a->dropoff_lat, $a->dropoff_lng) }}</p><p class="bz-queue__meta">Asked {{ $a->created_at->setTimezone($tz)->format('H:i') }}</p></div>
                                    <div class="bz-queue__amount"><x-eh.money :minor="$a->estimate_minor" :currency="$a->currency" /><span class="bz-queue__lapse">Lapses at {{ $a->decide_by->setTimezone($tz)->format('H:i') }}</span></div>
                                </div>
                                <form method="POST" action="{{ route('business.approvals.withdraw', $a->id) }}" style="margin:0">@csrf<x-eh.button type="submit" size="sm" variant="ghost">Withdraw</x-eh.button></form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-business-layout>
