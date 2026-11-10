@php
    use App\Domain\Payments\Money;

    $currency = $receipt->currency;
    $fmt = fn (int $minor) => Money::format($minor, $currency);
    $tz = config('app.timezone', 'UTC');
    $when = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone($tz)->format('j M Y, H:i') : null;
    $isRide = ($s['kind'] ?? 'ride') === 'ride';
    $distanceKm = isset($s['distance_m']) && $s['distance_m'] !== null ? number_format($s['distance_m'] / 1000, 1) . ' km' : null;
    $minutes = isset($s['duration_s']) && $s['duration_s'] !== null ? max(1, (int) round($s['duration_s'] / 60)) . ' min' : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Receipt {{ $receipt->number }}</title>
    <style>
        :root {
            --r-paper: var(--eh-surface, #fbfaf7);
            --r-ground: var(--eh-ground, #eceae4);
            --r-ink: var(--eh-ink, #1b1c1e);
            --r-ink-2: var(--eh-ink-2, #4a4c50);
            --r-rule: var(--eh-rule, #d9d6ce);
            --r-accent: var(--eh-go, #1f6b4a);
            --r-focus: var(--eh-focus, #1f5fbf);
            --r-font: var(--eh-font-ui, ui-sans-serif, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif);
            color-scheme: light dark;
        }
        @media screen and (prefers-color-scheme: dark) {
            :root {
                --r-paper: #1c1d1f;
                --r-ground: #121314;
                --r-ink: #ecebe7;
                --r-ink-2: #b4b2ab;
                --r-rule: #3a3b3e;
                --r-accent: #6fcf9f;
                --r-focus: #8ab4ff;
            }
        }
        * { box-sizing: border-box; }
        ::selection { background: color-mix(in srgb, var(--r-accent) 28%, transparent); }
        body {
            margin: 0;
            background: var(--r-ground);
            color: var(--r-ink);
            font: 400 15px/1.5 var(--r-font);
            font-variant-numeric: tabular-nums;
            -webkit-font-smoothing: antialiased;
        }
        .slip {
            max-width: 34rem;
            margin: 2.5rem auto;
            background: var(--r-paper);
            padding: 2.25rem 2rem 2rem;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgb(0 0 0 / .06), 0 12px 32px -12px rgb(0 0 0 / .18);
        }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
        h1 { font-size: 1.375rem; line-height: 1.2; font-weight: 650; margin: 0; letter-spacing: -0.01em; }
        .operator { margin: .25rem 0 0; color: var(--r-ink-2); font-size: .875rem; }
        .number { text-align: right; font-size: .875rem; color: var(--r-ink-2); }
        .number strong { display: block; color: var(--r-ink); font-weight: 600; font-size: .9375rem; }
        .paid {
            margin: 1.75rem 0 1.5rem;
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            padding-bottom: 1.25rem; border-bottom: 1px solid var(--r-rule);
        }
        .paid .amount { font-size: 2.25rem; font-weight: 650; letter-spacing: -0.02em; line-height: 1; }
        .paid .state { color: var(--r-accent); font-weight: 600; font-size: .9375rem; display: inline-flex; align-items: center; gap: .375rem; }
        .paid .state svg { width: 1rem; height: 1rem; }
        .route { list-style: none; margin: 0 0 1.25rem; padding: 0; }
        .route li { display: grid; grid-template-columns: 1.25rem 1fr; gap: .75rem; padding: .25rem 0; }
        .route .dot { width: .625rem; height: .625rem; border-radius: 50%; border: 2px solid var(--r-ink); margin-top: .45rem; }
        .route li:last-child .dot { background: var(--r-ink); }
        .route .when { color: var(--r-ink-2); font-size: .8125rem; }
        .facts { display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; color: var(--r-ink-2); font-size: .875rem; margin: 0 0 1.5rem; }
        .facts dt { display: inline; }
        .facts dd { display: inline; margin: 0 0 0 .25rem; color: var(--r-ink); font-weight: 500; }
        table { width: 100%; border-collapse: collapse; }
        caption { text-align: left; font-weight: 600; font-size: .9375rem; padding-bottom: .5rem; }
        td { padding: .4rem 0; vertical-align: top; }
        td.amt { text-align: right; white-space: nowrap; padding-left: 1rem; }
        tr.discount td { color: var(--r-accent); }
        tr.total td { border-top: 1px solid var(--r-rule); padding-top: .75rem; font-weight: 650; font-size: 1.0625rem; }
        tr.tax td { color: var(--r-ink-2); font-size: .875rem; padding-top: .125rem; }
        .changes { margin: 1.25rem 0 0; font-size: .875rem; color: var(--r-ink-2); }
        .changes h2 { margin: 0 0 .375rem; font-size: .9375rem; font-weight: 600; color: var(--r-ink); }
        .changes ul { margin: 0; padding-left: 1.1rem; display: grid; gap: .25rem; }
        .meta { margin: 1.5rem 0 0; padding-top: 1.25rem; border-top: 1px solid var(--r-rule); display: grid; grid-template-columns: max-content 1fr; gap: .375rem 1.25rem; font-size: .875rem; }
        .meta dt { color: var(--r-ink-2); }
        .meta dd { margin: 0; }
        footer { margin-top: 1.75rem; font-size: .8125rem; color: var(--r-ink-2); display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; justify-content: space-between; align-items: center; }
        a { color: var(--r-ink); text-underline-offset: 3px; text-decoration-thickness: 1px; }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--r-focus); outline-offset: 3px; border-radius: 2px; }
        .print {
            font: inherit; font-size: .875rem; font-weight: 600; color: var(--r-paper); background: var(--r-ink);
            border: 0; border-radius: 6px; padding: .625rem 1rem; min-height: 44px; cursor: pointer;
            transition: transform .15s cubic-bezier(.2,.8,.2,1), opacity .15s;
        }
        .print:hover { opacity: .88; }
        .print:active { transform: translateY(1px); }
        @media (max-width: 36rem) {
            .slip { margin: 0; border-radius: 0; box-shadow: none; min-height: 100vh; padding: 1.5rem 1rem 2rem; }
            .paid .amount { font-size: 1.875rem; }
        }
        @media (prefers-reduced-motion: reduce) { .print { transition: none; } }
        @media print {
            @page { size: A4; margin: 18mm; }
            :root { --r-paper: #fff; --r-ground: #fff; --r-ink: #000; --r-ink-2: #444; --r-rule: #bbb; --r-accent: #000; }
            body { font-size: 11pt; }
            .slip { box-shadow: none; margin: 0; max-width: none; padding: 0; }
            .no-print { display: none !important; }
            a { text-decoration: none; }
            a[href^="http"]::after { content: " (" attr(href) ")"; font-size: 9pt; }
        }
    </style>
</head>
<body>
<main class="slip">
    <header>
        <div>
            <h1>{{ $isRide ? 'Trip receipt' : 'Cancellation fee receipt' }}</h1>
            <p class="operator">{{ $s['operator_name'] ?? config('app.name') }}@if(!empty($s['vat_number'])) &middot; VAT {{ $s['vat_number'] }}@endif</p>
        </div>
        <div class="number">Receipt<strong>{{ $receipt->number }}</strong>{{ $when($receipt->issued_at->toIso8601String()) }}</div>
    </header>

    <div class="paid">
        <span class="amount">{{ $fmt($receipt->total_minor) }}</span>
        <span class="state">
            <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Paid &middot; {{ $s['payment_method'] ?? '' }}
        </span>
    </div>

    <ol class="route" aria-label="Route">
        <li><span class="dot" aria-hidden="true"></span><div>{{ $s['pickup_address'] ?? '' }}@if($when($s['started_at'] ?? null))<div class="when">Picked up {{ $when($s['started_at']) }}</div>@endif</div></li>
        <li><span class="dot" aria-hidden="true"></span><div>{{ $s['dropoff_address'] ?? '' }}@if($isRide && $when($s['completed_at'] ?? null))<div class="when">Dropped off {{ $when($s['completed_at']) }}</div>@elseif(!$isRide && $when($s['cancelled_at'] ?? null))<div class="when">Cancelled {{ $when($s['cancelled_at']) }}</div>@endif</div></li>
    </ol>

    @if($distanceKm || $minutes)
        <dl class="facts">
            @if($distanceKm)<div><dt>Distance</dt><dd>{{ $distanceKm }}</dd></div>@endif
            @if($minutes)<div><dt>Duration</dt><dd>{{ $minutes }}</dd></div>@endif
        </dl>
    @endif

    <table>
        <caption>Fare</caption>
        <tbody>
        @foreach(($s['components'] ?? []) as $line)
            <tr><td>{{ $line['label'] }}</td><td class="amt">{{ $fmt((int) $line['amount_minor']) }}</td></tr>
        @endforeach
        @if(($s['promotion_minor'] ?? 0) > 0)
            <tr class="discount"><td>Promotion</td><td class="amt">&minus;{{ $fmt((int) $s['promotion_minor']) }}</td></tr>
        @endif
        <tr class="total"><td>Total paid</td><td class="amt">{{ $fmt($receipt->total_minor) }}</td></tr>
        @if(!empty($s['tax_label']) && ($s['tax_minor'] ?? 0) > 0)
            <tr class="tax"><td>{{ $s['tax_label'] }}</td><td class="amt">{{ $fmt((int) $s['tax_minor']) }}</td></tr>
        @endif
        </tbody>
    </table>

    @if(!empty($s['fare_changes']))
        <section class="changes" aria-labelledby="fare-changes">
            <h2 id="fare-changes">Why the fare differs from the quote</h2>
            <ul>
                @foreach($s['fare_changes'] as $change)
                    <li>{{ $change['text'] }}@if(($change['amount_minor'] ?? 0) !== 0) <span class="amt">({{ $change['amount_minor'] > 0 ? '+' : '−' }}{{ $fmt(abs((int) $change['amount_minor'])) }})</span>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <dl class="meta">
        <dt>Ride</dt><dd>#{{ $s['ride_id'] ?? $receipt->ride_id }}</dd>
        @if(!empty($s['driver_first_name']))<dt>Driver</dt><dd>{{ $s['driver_first_name'] }}</dd>@endif
        @if(!empty($s['vehicle']))<dt>Vehicle</dt><dd>{{ $s['vehicle'] }}</dd>@endif
        <dt>Paid</dt><dd>{{ $when($s['paid_at'] ?? null) }}</dd>
    </dl>

    <footer>
        <span>
            Questions about this trip?
            @if(!empty($s['support_url']))<a href="{{ $s['support_url'] }}">Contact support</a>@elseif(!empty($s['support_email']))<a href="mailto:{{ $s['support_email'] }}">{{ $s['support_email'] }}</a>@else Reply to the receipt email.@endif
        </span>
        <button type="button" class="print no-print" onclick="window.print()">Print or save as PDF</button>
    </footer>
</main>
</body>
</html>
