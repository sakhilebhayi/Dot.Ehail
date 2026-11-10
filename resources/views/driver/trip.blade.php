{{-- One trip: final fare against the agreed fare, the earnings split, and what the driver can do about it. --}}
@php
    $s = $summary;
    $cur = $s['currency'];
    $f = $s['fare'];
    $e = $s['earnings'];
    $reportLabels = [
        'harassment' => 'Harassment', 'abusive_language' => 'Abusive language', 'discrimination' => 'Discrimination',
        'intoxication' => 'Intoxicated passenger', 'damage' => 'Damage to the vehicle', 'fraud' => 'Fraud or payment trick',
        'threatening_behaviour' => 'Threats', 'other' => 'Something else',
    ];
@endphp
<x-driver-layout title="Trip">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>{{ \Illuminate\Support\Str::limit($s['dropoff'], 60) }}</h1>
            <p>From {{ $s['pickup'] }}. <x-eh.status :status="$s['status']" />@if($s['completed_at']) {{ \App\Http\Controllers\Driver\Support\Local::at($s['completed_at'])->isoFormat('D MMMM YYYY, HH:mm') }}@endif</p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        <section class="dr-panel" aria-labelledby="fare-h">
            <h2 id="fare-h">Fare and earnings</h2>
            <dl class="dr-ledger">
                @if ($f['quoted_minor'] !== null)<div><dt>Fare agreed at request</dt><dd><x-eh.money :minor="$f['quoted_minor']" :currency="$cur" /></dd></div>@endif
                @if ($f['final_minor'] !== null)<div class="is-total"><dt>Final fare</dt><dd><x-eh.money :minor="$f['final_minor']" :currency="$cur" /></dd></div>@endif
                @if ($f['difference_minor'])
                    <div><dt>Difference</dt><dd><x-eh.money :minor="$f['difference_minor']" :currency="$cur" signed /></dd></div>
                @endif
                @foreach ($f['adjustments'] as $adj)
                    <div><dt>{{ $adj['text'] ?? $adj['label'] ?? $adj['reason_label'] ?? $adj['reason'] ?? $adj['code'] ?? 'Adjustment' }}</dt><dd>@isset($adj['amount_minor'])<x-eh.money :minor="$adj['amount_minor']" :currency="$cur" signed />@endisset</dd></div>
                @endforeach
            </dl>
            @if ($e)
                <dl class="dr-ledger">
                    @if (! empty($e['fee_lines']))
                        @foreach ($e['fee_lines'] as $line)<div><dt>{{ $line['label'] }}</dt><dd><x-eh.money :minor="(int) $line['amount_minor']" :currency="$cur" /></dd></div>@endforeach
                    @elseif ($e['deductions_minor'])<div><dt>Platform fees, tax and other</dt><dd><x-eh.money :minor="-$e['deductions_minor']" :currency="$cur" /></dd></div>@endif
                    <div><dt>Commission @if(! empty($e['commission_detail']))<span class="dr-note">({{ $e['commission_detail'] }})</span>@endif</dt><dd>@if($e['commission_minor'] !== null)<x-eh.money :minor="-$e['commission_minor']" :currency="$cur" />@else not configured @endif</dd></div>
                    <div class="is-total"><dt>You earn</dt><dd>@if($e['net_minor'] !== null)<x-eh.money :minor="$e['net_minor']" :currency="$cur" />@else not configured @endif</dd></div>
                    @if ($e['cash_collected_minor'] !== null)<div><dt>Cash you collected</dt><dd><x-eh.money :minor="$e['cash_collected_minor']" :currency="$cur" /></dd></div>@endif
                </dl>
                <p class="dr-note">{{ $e['source'] === 'statement' ? 'From your earnings statement ('.($e['status'] ?? 'pending').').' : 'From the fare record; it moves to your statement once the payment settles.' }}</p>
            @else
                <p class="dr-note">No earnings were recorded for this trip{{ in_array($s['status'], ['completed'], true) ? ' yet' : '' }}.</p>
            @endif
            @if ($f['actual_distance_m'] || $f['waiting_seconds'])
                <p class="dr-note">@if($f['actual_distance_m'])Distance {{ number_format($f['actual_distance_m'] / 1000, 1) }} km. @endif @if($f['waiting_seconds'])Waiting {{ intdiv($f['waiting_seconds'], 60) }} min.@endif</p>
            @endif
            <p class="dr-note">Payment: {{ ['cash' => 'cash', 'card' => 'card', 'business_account' => 'business account'][$s['payment_method']] ?? 'not recorded' }}. Vehicle: {{ $s['vehicle'] ?? 'not recorded' }}.</p>
        </section>

        @if ($canRate)
            <section class="dr-section" aria-labelledby="rate-h">
                <h2 id="rate-h">Rate the passenger</h2>
                <form class="dr-form" method="POST" action="{{ route('safety.rides.rating', $ride) }}" data-dr-json data-done="Thanks, your rating was saved.">
                    @csrf
                    <fieldset class="dr-fieldset">
                        <legend class="eh-field__label">Rating</legend>
                        <div class="dr-actions__row">
                            @foreach ([5, 4, 3, 2, 1] as $n)
                                <label class="dr-reason"><input type="radio" name="rating" value="{{ $n }}" required> {{ $n }} {{ \Illuminate\Support\Str::plural('star', $n) }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div data-dr-typing><x-eh.field label="Comment" name="comment" type="textarea" optional maxlength="500" /></div>
                    <x-eh.button type="submit" size="driver">Save rating</x-eh.button>
                </form>
            </section>
        @elseif ($myRating)
            <p class="dr-note">You rated this passenger {{ $myRating->rating }} {{ \Illuminate\Support\Str::plural('star', $myRating->rating) }}.</p>
        @endif

        <details class="dr-more" data-dr-typing>
            <summary>Report the passenger</summary>
            <form class="dr-form dr-more__body" method="POST" action="{{ route('safety.rides.report', $ride) }}" data-dr-json>
                @csrf
                <x-eh.select label="What happened" name="category" :options="collect($reportCategories)->mapWithKeys(fn ($c) => [$c => $reportLabels[$c] ?? ucfirst(str_replace('_', ' ', $c))])->all()" />
                <x-eh.field label="Details" name="description" type="textarea" optional maxlength="2000" />
                <p class="dr-note">Reports are private: the passenger is not told who reported them. For danger right now, use SOS or call 10111.</p>
                <x-eh.button type="submit" size="driver" variant="secondary">Send report</x-eh.button>
            </form>
        </details>

        <details class="dr-more" data-dr-typing>
            <summary>Ask support about this trip</summary>
            <form class="dr-form dr-more__body" method="POST" action="{{ route('support.cases.store') }}" data-dr-json data-done="Your case is open. Replies appear under Help and safety.">
                @csrf
                <input type="hidden" name="ride_id" value="{{ $ride->id }}">
                <x-eh.select label="Topic" name="category" :options="['post_trip' => 'After a trip', 'fare_dispute' => 'Fare or earnings question', 'lost_property' => 'Item left in the car', 'passenger_conduct' => 'Passenger conduct', 'payment_issue' => 'Payment issue']" />
                <x-eh.field label="Subject" name="subject" maxlength="160" required />
                <x-eh.field label="Message" name="body" type="textarea" maxlength="5000" required />
                <x-eh.button type="submit" size="driver" variant="secondary">Open a case</x-eh.button>
            </form>
        </details>
        <p class="dr-hold dr-alert dr-alert--caution">Forms are paused while you drive.</p>
    </div>
</x-driver-layout>
