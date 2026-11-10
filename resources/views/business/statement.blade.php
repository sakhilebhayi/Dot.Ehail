<x-business-layout :title="'Statement '.$statement->number" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php
        $s = $statement->snapshot;
        $cur = $statement->currency;
        $tz = config('ehail_business.timezone');
    @endphp
    <div class="bz-doc__bar">
        <a class="eh-link" href="{{ route('business.invoices') }}">All statements</a>
        <div class="bz-title__actions">
            <x-eh.button variant="secondary" size="sm" icon="download" :href="route('business.statements.csv', $statement)">CSV</x-eh.button>
            <x-eh.button size="sm" icon="document" data-eh-print>Print or save as PDF</x-eh.button>
        </div>
    </div>
    <article class="bz-doc" aria-labelledby="bz-doc-title">
        <header class="bz-doc__head">
            <div>
                <x-eh.logo />
                <h1 class="bz-doc__title" id="bz-doc-title">Statement</h1>
                <p style="margin:0">{{ $statement->number }} · {{ $s['period_label'] ?? $statement->period }}</p>
            </div>
            <div style="text-align:right">
                <x-eh.badge :tone="$statement->statusTone()">{{ $statement->statusLabel() }}</x-eh.badge>
                <p style="margin:var(--eh-space-3) 0 0">Issued {{ $statement->issued_at->setTimezone($tz)->format('j F Y') }}</p>
                <p style="margin:0">{{ $statement->billing_mode === 'card' ? 'Charged to the company card' : 'Due '.$statement->due_at->setTimezone($tz)->format('j F Y') }}</p>
            </div>
        </header>
        <div class="bz-doc__parties">
            <div>
                <h2>Billed to</h2>
                <p><strong>{{ $s['account']['legal_name'] }}</strong>@if ($s['account']['trading_name'])<br>Trading as {{ $s['account']['trading_name'] }}@endif<br>Registration {{ $s['account']['registration_number'] }}@if ($s['account']['vat_number'])<br>VAT number {{ $s['account']['vat_number'] }}@endif</p>
            </div>
            <div>
                <h2>Address</h2>
                <p>{!! collect($s['account']['address'])->map(fn ($l) => e($l))->implode('<br>') !!}<br>{{ $s['account']['billing_email'] }}</p>
            </div>
            <div>
                <h2>From</h2>
                <p><strong>{{ $s['operator']['name'] }}</strong>@if ($s['operator']['vat_number'])<br>VAT number {{ $s['operator']['vat_number'] }}@endif @if ($s['operator']['support_email'])<br>{{ $s['operator']['support_email'] }}@endif</p>
            </div>
        </div>

        <div class="bz-tablewrap">
            <table class="bz-table">
                <caption class="eh-sr-only">Charges in {{ $s['period_label'] ?? $statement->period }}</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Rider</th><th scope="col">Department</th><th scope="col">Purpose</th><th scope="col">Trip</th><th scope="col" class="num">Amount</th></tr></thead>
                <tbody>
                    @forelse ($s['lines'] as $line)
                        <tr>
                            <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->setTimezone($tz)->format('j M H:i') }}<span class="bz-table__sub">{{ $line['reference'] }}</span></td>
                            <td>{{ $line['rider'] ?? '' }}@if (! empty($line['booked_by']))<span class="bz-table__sub">Booked by {{ $line['booked_by'] }}</span>@endif</td>
                            <td>{{ $line['department'] ?? '' }}@if (! empty($line['cost_centre']))<span class="bz-table__sub">{{ $line['cost_centre'] }}</span>@endif</td>
                            <td>{{ $line['purpose'] ?? '' }}</td>
                            <td>
                                @if ($line['kind'] === 'cancellation_fee')Cancellation fee @elseif ($line['kind'] === 'adjustment'){{ $line['description'] }} @endif
                                @if (! empty($line['pickup']))<div class="bz-route"><span><i class="bz-stop bz-stop--pickup" aria-hidden="true"></i>{{ $line['pickup'] }}</span><span><i class="bz-stop bz-stop--dropoff" aria-hidden="true"></i>{{ $line['dropoff'] }}</span></div>@endif
                            </td>
                            <td class="num"><x-eh.money :minor="$line['amount_minor']" :currency="$cur" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="eh-muted">No rides were billed in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <dl class="bz-facts" style="margin-top:var(--eh-space-8);max-width:28rem;margin-left:auto">
            <div><dt>Balance brought forward</dt><dd><x-eh.money :minor="$statement->opening_minor" :currency="$cur" /></dd></div>
            <div><dt>Charges this period</dt><dd><x-eh.money :minor="$statement->charges_minor" :currency="$cur" /></dd></div>
            @if ($s['vat_shown'] ?? false)
                <div><dt>Includes VAT at {{ rtrim(rtrim(number_format((float) $s['vat_rate_percent'], 2), '0'), '.') }}%</dt><dd><x-eh.money :minor="$statement->tax_minor" :currency="$cur" /></dd></div>
            @endif
            <div><dt>Payments received</dt><dd><x-eh.money :minor="-$statement->payments_minor" :currency="$cur" /></dd></div>
        </dl>
        <div class="bz-doc__total" style="max-width:28rem;margin-left:auto">
            <span>{{ $statement->closing_minor >= 0 ? 'Balance due' : 'In credit' }}</span>
            <x-eh.money :minor="abs($statement->closing_minor)" :currency="$cur" />
        </div>
        @if ($owed !== $statement->closing_minor && $statement->closing_minor > 0)
            <p class="bz-doc__owed">Still owed today: {{ \App\Support\Ui\MoneyFormatter::format($owed, $cur) }}</p>
        @endif
        @if (! empty($s['credits']))
            <h2 class="eh-h3" style="margin-top:var(--eh-space-8)">Payments in this period</h2>
            <dl class="bz-facts">@foreach ($s['credits'] as $c)<div><dt>{{ \Carbon\CarbonImmutable::parse($c['date'])->setTimezone($tz)->format('j M') }} · {{ $c['description'] }}</dt><dd><x-eh.money :minor="$c['amount_minor']" :currency="$cur" /></dd></div>@endforeach</dl>
        @endif
        <p class="bz-doc__note">
            @if ($statement->billing_mode === 'card') The balance is charged to the company card on file. @else Pay by EFT within {{ $s['terms_days'] }} days and quote {{ $statement->number }} as the reference. @endif
            Amounts are what each trip cost after the trip, as on the riders' receipts. @unless ($s['vat_shown'] ?? false) No VAT line is shown because the operator is not VAT registered or the fares carried no VAT. @endunless
        </p>
    </article>
</x-business-layout>
