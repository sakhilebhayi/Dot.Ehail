@php
    use App\Domain\Payments\Money;
    use App\Domain\Payments\OperatorIdentity;
    use App\Support\Ui\LocalTime;

    $currency = $receipt->currency;
    $fmt = fn (int $minor) => Money::format($minor, $currency);
    // Every time on the receipt is South African Standard Time (D-1906); the label is printed once, by the issue time.
    $when = fn (?string $iso) => $iso ? LocalTime::format($iso, 'j M Y, H:i') : null;
    $zone = LocalTime::label();
    $isRide = ($s['kind'] ?? 'ride') === 'ride';
    $distanceKm = isset($s['distance_m']) && $s['distance_m'] !== null ? \App\Domain\Pricing\Engine\FareEngine::km((int) $s['distance_m']) : null;
    $minutes = isset($s['duration_s']) && $s['duration_s'] !== null ? max(1, (int) round($s['duration_s'] / 60)) . ' min' : null;
    $billed = $s['billed'] ?? ['distance' => null, 'time' => null];
    $quotedStood = ($s['fare_basis'] ?? null) === 'quoted_fare_stands';
    // Older receipts froze only a name; newer ones freeze the whole configured identity (D-1905).
    $op = $s['operator'] ?? ['brand' => config('app.name'), 'legal_name' => $s['operator_name'] ?? null, 'vat_status' => ! empty($s['vat_number']) ? OperatorIdentity::VAT_REGISTERED : OperatorIdentity::VAT_NOT_CONFIGURED, 'vat_number' => $s['vat_number'] ?? null, 'address_lines' => [], 'registration_number' => null, 'trading_name' => null];
    $vatSentence = $s['vat_sentence'] ?? OperatorIdentity::vatSentence($op, (int) ($s['tax_minor'] ?? 0));
    $supportEmail = ! empty($s['support_email']) && ! OperatorIdentity::isNoReply((string) $s['support_email']) ? $s['support_email'] : null;
    // Route Line finish (ppass, D-2012): the shared head (fonts, the person's own light or night choice, SAST meta),
    // places as a name and its area (no postcode or country), and a way back to the trip for the passenger.
    $brand = $op['brand'] ?? config('app.name');
    $operatorLine = implode(', ', array_filter([($op['legal_name'] ?? null) !== $brand ? ($op['legal_name'] ?? null) : null, ! empty($op['vat_number']) ? 'VAT '.$op['vat_number'] : null]));
    $place = fn (?string $label) => \App\Domain\Passenger\PlaceLabel::split($label);
    $from = $place($s['pickup_address'] ?? '');
    $to = $place($s['dropoff_address'] ?? '');
    $mine = auth()->id() === $receipt->passenger_id;
    $rideId = $s['ride_id'] ?? $receipt->ride_id;
@endphp
<!DOCTYPE html>
<html lang="en-ZA" class="eh">
<head>
    @include('layouts.partials.eh-head', ['title' => 'Receipt '.$receipt->number, 'viteEntries' => ['resources/css/app.css']])
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --r-paper: var(--eh-surface, #ffffff);
            --r-ground: var(--eh-ground, #f1f4f0);
            --r-ink: var(--eh-ink, #0f1f19);
            --r-ink-2: var(--eh-ink-2, #3a4a43);
            --r-rule: var(--eh-rule, #d3dbd5);
            --r-accent: var(--eh-go, #0e6e3d);
            --r-focus: var(--eh-focus, #1f5fbf);
            --r-font: var(--eh-font-ui, system-ui, sans-serif);
            --r-display: var(--eh-font-display, var(--r-font));
            color-scheme: light dark;
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
        h1 { font-family: var(--r-display); font-size: 1.75rem; line-height: 1.1; font-weight: 700; margin: .75rem 0 0; }
        .operator { margin: .25rem 0 0; color: var(--r-ink-2); font-size: .875rem; }
        .number { text-align: right; font-size: .875rem; color: var(--r-ink-2); }
        .number strong { display: block; color: var(--r-ink); font-weight: 600; font-size: .9375rem; }
        .paid {
            margin: 1.75rem 0 1.5rem;
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            padding-bottom: 1.25rem; border-bottom: 1px solid var(--r-rule);
        }
        .paid .amount { font-family: var(--r-display); font-size: 2.5rem; font-weight: 650; letter-spacing: -0.02em; line-height: 1; }
        .paid .state { color: var(--r-accent); font-weight: 600; font-size: .9375rem; display: inline-flex; align-items: center; gap: .375rem; }
        .paid .state svg { width: 1rem; height: 1rem; }
        .route { list-style: none; margin: 0 0 1.25rem; padding: 0; display: grid; gap: 1rem; }
        .route li { position: relative; display: grid; grid-template-columns: .875rem minmax(0, 1fr); gap: .875rem; }
        .route .dot { width: .875rem; height: .875rem; border-radius: 50%; background: var(--eh-map-pickup, var(--r-paper)); box-shadow: inset 0 0 0 2px var(--r-ink); margin-top: .3rem; }
        .route li:last-child .dot { border-radius: 3px; background: var(--eh-map-dropoff, var(--r-ink)); box-shadow: none; }
        .route li:first-child::after { content: ""; position: absolute; left: calc(.4375rem - 1px); top: 1.3rem; bottom: -1.2rem; width: 2px; background: var(--r-accent); }
        .route .name { font-weight: 600; overflow-wrap: anywhere; }
        .route .area, .route .when { color: var(--r-ink-2); font-size: .8125rem; }
        .back { display: inline-flex; align-items: center; gap: .375rem; min-height: 44px; margin: -.75rem 0 .5rem; font-size: .875rem; font-weight: 600; text-decoration: none; }
        .back:hover { text-decoration: underline; }
        .back svg { width: 1rem; height: 1rem; }
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
        td .detail { display: block; color: var(--r-ink-2); font-size: .8125rem; }
        .facts .note { flex-basis: 100%; font-size: .8125rem; }
        .issuer { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--r-rule); font-size: .8125rem; color: var(--r-ink-2); }
        .issuer p { margin: 0 0 .25rem; }
        .issuer strong { color: var(--r-ink); font-weight: 600; }
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
    @if($mine && Route::has('ride.trip'))
        <a class="back no-print" href="{{ route('ride.trip', $rideId) }}"><svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3 5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Back to the trip</a>
    @endif
    <header>
        <div>
            <x-eh.logo :size="28" />
            <h1>{{ $isRide ? 'Trip receipt' : 'Cancellation fee receipt' }}</h1>
            @if($operatorLine !== '')<p class="operator">{{ $operatorLine }}</p>@endif
        </div>
        <div class="number">Receipt<strong>{{ $receipt->number }}</strong>{{ $when($receipt->issued_at->toIso8601String()) }} {{ $zone }}</div>
    </header>

    <div class="paid">
        <span class="amount">{{ $fmt($receipt->total_minor) }}</span>
        <span class="state">
            <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5 6.5 12 13 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Paid{{ ! empty($s['payment_method']) ? ', '.$s['payment_method'] : '' }}
        </span>
    </div>

    <ol class="route" aria-label="Route">
        <li><span class="dot" aria-hidden="true"></span><div><div class="name">{{ $from['name'] }}</div>@if($from['detail'] !== '')<div class="area">{{ $from['detail'] }}</div>@endif @if($when($s['started_at'] ?? null))<div class="when">Picked up {{ $when($s['started_at']) }}</div>@endif</div></li>
        <li><span class="dot" aria-hidden="true"></span><div><div class="name">{{ $to['name'] }}</div>@if($to['detail'] !== '')<div class="area">{{ $to['detail'] }}</div>@endif @if($isRide && $when($s['completed_at'] ?? null))<div class="when">Dropped off {{ $when($s['completed_at']) }}</div>@elseif(!$isRide && $when($s['cancelled_at'] ?? null))<div class="when">Cancelled {{ $when($s['cancelled_at']) }}</div>@endif</div></li>
    </ol>

    @if($billed['distance'] || $billed['time'])
        {{-- The fare lines below are billed on these quantities (D-1904); the measured trip is shown beside them. --}}
        <dl class="facts">
            @if($billed['distance'])<div><dt>Billed distance</dt><dd>{{ $billed['distance'] }}</dd></div>@endif
            @if($billed['time'])<div><dt>Billed time</dt><dd>{{ $billed['time'] }}</dd></div>@endif
            @if($distanceKm || $minutes)<div><dt>Your trip</dt><dd>{{ implode(', ', array_filter([$distanceKm, $minutes])) }}</dd></div>@endif
            @if($quotedStood)<p class="note">The trip stayed within the fare rules, so the quoted fare stood: distance and time are billed as quoted, not as measured.</p>@endif
        </dl>
    @elseif($distanceKm || $minutes)
        <dl class="facts">
            @if($distanceKm)<div><dt>Distance</dt><dd>{{ $distanceKm }}</dd></div>@endif
            @if($minutes)<div><dt>Duration</dt><dd>{{ $minutes }}</dd></div>@endif
        </dl>
    @endif

    <table>
        <caption>Fare</caption>
        <tbody>
        @foreach(($s['components'] ?? []) as $line)
            <tr><td>{{ $line['label'] }}@if(!empty($line['detail']) && isset($line['quantity']))<span class="detail">{{ $line['detail'] }}</span>@endif</td><td class="amt">{{ $fmt((int) $line['amount_minor']) }}</td></tr>
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

    <dl class="meta">
        <dt>Trip reference</dt><dd>{{ $rideId }}</dd>
        @if(!empty($s['driver_first_name']))<dt>Driver</dt><dd>{{ $s['driver_first_name'] }}</dd>@endif
        @if(!empty($s['vehicle']))<dt>Vehicle</dt><dd>{{ $s['vehicle'] }}</dd>@endif
        <dt>Paid</dt><dd>{{ $when($s['paid_at'] ?? null) }}</dd>
    </dl>

    <section class="issuer" aria-label="Issued by">
        @if(!empty($op['legal_name']))<p><strong>{{ $op['legal_name'] }}</strong>@if(!empty($op['trading_name'])), trading as {{ $op['trading_name'] }}@endif</p>@endif
        @if(!empty($op['registration_number']))<p>Registration number {{ $op['registration_number'] }}</p>@endif
        @if(!empty($op['address_lines']))<p>{{ implode(', ', $op['address_lines']) }}</p>@endif
        @if($vatSentence)<p>{{ $vatSentence }}</p>@endif
        <p>Times are {{ $zone === 'SAST' ? 'South African Standard Time (SAST)' : $zone }}.</p>
    </section>

    <footer>
        <span>
            Questions about this trip?
            @if(!empty($s['support_url']))<a href="{{ $s['support_url'] }}">Contact support</a>@endif
            @if($supportEmail)<a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>@endif
            @if(!empty($s['support_phone']))<a href="tel:{{ preg_replace('/[^+0-9]/', '', $s['support_phone']) }}">{{ $s['support_phone'] }}</a>@endif
            @if(empty($s['support_url']) && ! $supportEmail && empty($s['support_phone']) && Route::has('ride.support'))<a href="{{ route('ride.support') }}">Open a help case in the app</a>@endif
        </span>
        <button type="button" class="print no-print" data-eh-print>Print or save as PDF</button>
    </footer>
</main>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-eh-print]');if(b){e.preventDefault();window.print();}});</script>
</body>
</html>
