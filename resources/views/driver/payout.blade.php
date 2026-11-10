{{-- One payout and exactly what it is made of. --}}
<x-driver-layout title="Payout">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Payout for the week to {{ \App\Http\Controllers\Driver\Support\Local::at($payout->batch?->period_end)?->isoFormat('D MMMM YYYY') ?? 'date not set' }}</h1>
            <p>Reference {{ $payout->reference }}. Status: {{ ucfirst($payout->status) }}@if($payout->paid_at), paid {{ \App\Http\Controllers\Driver\Support\Local::at($payout->paid_at)->isoFormat('D MMMM HH:mm') }}@endif.@if($payout->failure_reason) {{ $payout->failure_reason }}@endif</p>
        </div>
    </x-slot:header>
    <div class="dr-page">
        <section class="dr-panel" aria-labelledby="comp-h">
            <h2 id="comp-h">What it pays</h2>
            <dl class="dr-ledger">
                <div><dt>Earnings ({{ $earnings->count() }} {{ \Illuminate\Support\Str::plural('item', $earnings->count()) }})</dt><dd><x-eh.money :minor="$payout->earnings_minor" :currency="$payout->currency" /></dd></div>
                <div><dt>Cash you held, netted</dt><dd><x-eh.money :minor="-$payout->cash_offset_minor" :currency="$payout->currency" /></dd></div>
                <div class="is-total"><dt>Paid to {{ $payout->account?->bank_name ?? 'your account' }} {{ $payout->account ? $payout->account->toPublicArray()['account_masked'] : '' }}</dt><dd><x-eh.money :minor="$payout->amount_minor" :currency="$payout->currency" /></dd></div>
            </dl>
        </section>
        <ul class="eh-list" aria-label="Earnings in this payout">
            @foreach ($earnings as $e)
                <li>
                    <a class="dr-trip-row" href="{{ $e->ride_id ? route('drive.trips.show', $e->ride_id) : '#' }}">
                        <span class="dr-trip-row__title">{{ ucfirst(str_replace('_', ' ', $e->kind)) }}{{ $e->ride_id ? ' #'.$e->ride_id : '' }}</span>
                        <span class="dr-trip-row__meta">{{ \App\Http\Controllers\Driver\Support\Local::at($e->earned_at)?->isoFormat('D MMM HH:mm') }}, fare <x-eh.money :minor="$e->fare_minor" :currency="$e->currency" /></span>
                        <span class="dr-trip-row__amount"><x-eh.money :minor="$e->net_minor ?? 0" :currency="$e->currency" /></span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-eh.button variant="ghost" size="driver" :href="route('drive.earnings.payouts')" icon="arrow-left">All payouts</x-eh.button>
    </div>
</x-driver-layout>
