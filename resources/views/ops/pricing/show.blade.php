@php
    $rand = fn (int $minor) => \App\Domain\Pricing\Money\MoneyFormatter::decimal($minor, 2);
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    $moneyTerms = [
        'base_minor' => 'Base fare', 'per_km_minor' => 'Per kilometre', 'per_minute_minor' => 'Per minute', 'minimum_minor' => 'Minimum fare',
        'booking_fee_minor' => 'Booking fee', 'wait_per_minute_minor' => 'Waiting, per minute', 'cancel_fee_minor' => 'Cancellation fee', 'no_show_fee_minor' => 'No-show fee',
    ];
    $editable = $canManage && $set->status === 'draft';
@endphp
<x-ops-layout :title="$set->name">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.pricing') }}">Pricing</a> / {{ $set->name }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>{{ $set->name }} v{{ $set->version }} @include('ops.pricing.status', ['v' => $set])</h1>
                <p class="ops-head__finding">{{ $set->category?->name ?? 'Every category' }} · {{ $set->service_area_id ? ($areas[$set->service_area_id] ?? 'area '.$set->service_area_id) : 'whole market ('.$set->country.')' }}{{ $set->zone_slug ? ' · pickup zone '.$set->zone_slug : '' }} · {{ $set->currency }}{{ $set->effective_from ? ' · in force from '.$set->effective_from->format('d M Y H:i') : '' }}{{ $set->retired_at ? ' to '.$set->retired_at->format('d M Y H:i') : '' }}</p>
            </div>
            <div class="ops-head__actions">
                <x-eh.button size="sm" variant="secondary" :href="route('ops.pricing.simulator', ['rule_set' => $set->id])" icon="sliders">Simulate</x-eh.button>
                @if ($canManage && ! $hasDraft && $set->status !== 'draft')
                    <form data-ops-action method="post" action="{{ route('pricing.ops.rule-sets.version', $set->id) }}" data-ops-done="New draft version created." data-ops-redirect-template="{{ url('/ops/tariffs/{id}') }}" data-ops-redirect-key="rule_set.id" style="margin:0">
                        <x-eh.button type="submit" size="sm" icon="edit">New draft from this version</x-eh.button>
                    </form>
                @endif
            </div>
        </header>
        @if ($set->notes)<p class="ops-note" style="margin-bottom:var(--eh-space-4)">{{ $set->notes }}</p>@endif

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="terms-h">
                    <div class="ops-panel__head"><h2 id="terms-h">{{ $editable ? 'Edit the draft' : 'Terms' }}</h2><span class="ops-panel__meta">{{ $editable ? 'saved through the pricing API, which validates every term' : 'published versions never change' }}</span></div>
                    @if ($editable)
                        <form class="ops-act" data-ops-action data-ops-method="PATCH" method="post" action="{{ route('pricing.ops.rule-sets.update', $set->id) }}" data-ops-done="Draft saved.">
                            <x-eh.field label="Name" name="name" :value="$set->name" required maxlength="120" id="t-name" />
                            <div class="ops-cols ops-cols--2">
                                @foreach ($moneyTerms as $field => $label)
                                    <x-eh.field :label="$label.' (rand)'" :name="str_replace('_minor', '_rand', $field)" :value="$rand($set->{$field})" inputmode="decimal" :data-ops-minor="$field" :id="'t-'.$field" />
                                @endforeach
                                <x-eh.field label="Free waiting (minutes)" name="wait_free_minutes" type="number" min="0" max="60" :value="$set->wait_free_minutes" id="t-wfm" />
                                <x-eh.field label="Free cancellation (minutes)" name="cancel_free_minutes" type="number" min="0" max="60" :value="$set->cancel_free_minutes" id="t-cfm" />
                                <x-eh.select label="Tolls" name="toll_mode" :options="['none' => 'Not charged', 'pass_through' => 'Passed through at cost']" :value="$set->toll_mode" id="t-toll" />
                                <x-eh.select label="Rounding" name="rounding_unit_minor" :options="['1' => 'To the cent', '5' => '5 cents', '10' => '10 cents', '50' => '50 cents', '100' => 'Whole rand', '500' => 'R5', '1000' => 'R10']" :value="(string) $set->rounding_unit_minor" id="t-round" />
                                <x-eh.select label="Rounding mode" name="rounding_mode" :options="['half_up' => 'Half up', 'half_even' => 'Half even', 'up' => 'Always up', 'down' => 'Always down']" :value="$set->rounding_mode" id="t-rmode" />
                                <x-eh.select label="Tax" name="tax_mode" :options="['none' => 'None', 'inclusive' => 'Included in the fare', 'exclusive' => 'Added to the fare']" :value="$set->tax_mode" id="t-tax" />
                                <x-eh.field label="Tax rate (basis points, 1500 = 15%)" name="tax_rate_bp" type="number" min="0" max="5000" :value="$set->tax_rate_bp" id="t-taxbp" />
                                <x-eh.field label="Tax label" name="tax_label" :value="$set->tax_label" maxlength="32" id="t-taxl" :optional="true" />
                                <x-eh.field label="Demand cap (basis points, 15000 = 1.5x)" name="demand_cap_bp" type="number" min="10000" max="30000" :value="$set->demand_cap_bp" id="t-dcap" />
                                <x-eh.field label="Distance tolerance (bp)" name="distance_tolerance_bp" type="number" min="0" max="10000" :value="$set->distance_tolerance_bp" id="t-dtol" />
                                <x-eh.field label="Duration tolerance (bp)" name="duration_tolerance_bp" type="number" min="0" max="10000" :value="$set->duration_tolerance_bp" id="t-ttol" />
                                <x-eh.field label="Overrun cap (bp)" name="overrun_cap_bp" type="number" min="0" max="10000" :value="$set->overrun_cap_bp" id="t-ocap" />
                            </div>
                            <input type="hidden" name="demand_enabled" value="0">
                            <label class="ops-check"><input type="checkbox" name="demand_enabled" value="1" @checked($set->demand_enabled)> Demand pricing on (capped, always shown as its own line; no demand signal exists yet)</label>
                            <x-eh.field label="Notes" name="notes" type="textarea" :value="$set->notes" maxlength="2000" id="t-notes" :optional="true" />
                            <p class="ops-note ops-note--small">Time windows and zone fees are kept as they are: {{ $set->time_windows ? count($set->time_windows).' window(s)' : 'always' }}; {{ $set->zone_fees ? implode(', ', array_keys($set->zone_fees)) : 'no zone fees' }}.</p>
                            <div class="ops-act__row"><x-eh.button type="submit" size="sm">Save draft</x-eh.button></div>
                        </form>
                    @else
                        <table class="ops-table ops-table--compact" style="margin:0 calc(-1 * var(--eh-space-5));width:calc(100% + 2 * var(--eh-space-5))">
                            <tbody>
                                @foreach ($moneyTerms as $field => $label)<tr><td>{{ $label }}</td><td class="num"><x-eh.money :minor="$set->{$field}" :currency="$set->currency" /></td></tr>@endforeach
                                <tr><td>Free waiting</td><td class="num">{{ $set->wait_free_minutes }} min</td></tr>
                                <tr><td>Free cancellation after assignment</td><td class="num">{{ $set->cancel_free_minutes }} min</td></tr>
                                <tr><td>Tolls</td><td class="num">{{ $set->toll_mode === 'pass_through' ? 'passed through' : 'not charged' }}</td></tr>
                                <tr><td>Demand pricing</td><td class="num">{{ $set->demand_enabled ? 'on, capped at '.number_format($set->demand_cap_bp / 10000, 2).'x' : 'off' }}</td></tr>
                                <tr><td>Tax</td><td class="num">{{ $set->tax_mode === 'none' ? 'none' : $set->tax_mode.' '.$pct($set->tax_rate_bp).' '.$set->tax_label }}</td></tr>
                                <tr><td>Rounding</td><td class="num">{{ $set->rounding_unit_minor === 100 ? 'whole rand' : $set->rounding_unit_minor.' cents' }}, {{ str_replace('_', ' ', $set->rounding_mode) }}</td></tr>
                                <tr><td>Final fare tolerance</td><td class="num">distance {{ $pct($set->distance_tolerance_bp) }}, time {{ $pct($set->duration_tolerance_bp) }}, overrun cap {{ $pct($set->overrun_cap_bp) }}</td></tr>
                                <tr><td>Zone fees</td><td class="num">{{ $set->zone_fees ? implode(', ', array_keys($set->zone_fees)) : 'none' }}</td></tr>
                                <tr><td>Time windows</td><td class="num">{{ $set->time_windows ? count($set->time_windows).' window(s)' : 'always' }}</td></tr>
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>

            <div class="ops-stack">
                @if ($canManage && $set->status === 'draft')
                    <section class="ops-panel" aria-labelledby="pub-h">
                        <div class="ops-panel__head"><h2 id="pub-h">Publish</h2></div>
                        <p class="ops-note ops-note--small" style="margin-bottom:var(--eh-space-3)">Publishing freezes this version and retires the live one at the same moment. Quotes from then on use it. Simulate first.</p>
                        <form class="ops-act" data-ops-action method="post" action="{{ route('pricing.ops.rule-sets.publish', $set->id) }}" data-ops-confirm="Publish {{ $set->name }} v{{ $set->version }}? Passengers are quoted with it from the start time, and it can never be edited again." data-ops-done="Published.">
                            <x-eh.field label="Start (empty = now)" name="effective_from" type="datetime-local" id="pub-at" :optional="true" />
                            <div class="ops-act__row"><x-eh.button type="submit" size="sm">Publish v{{ $set->version }}</x-eh.button></div>
                        </form>
                    </section>
                @elseif ($canManage && $set->status === 'published' && $set->retired_at === null)
                    <section class="ops-panel" aria-labelledby="ret-h">
                        <div class="ops-panel__head"><h2 id="ret-h">Retire</h2></div>
                        <form class="ops-act" data-ops-action method="post" action="{{ route('pricing.ops.rule-sets.retire', $set->id) }}" data-ops-confirm="Retire {{ $set->name }}? Without another live tariff, these trips cannot be quoted." data-ops-done="Retired.">
                            <x-eh.field label="When (empty = now)" name="at" type="datetime-local" id="ret-at" :optional="true" />
                            <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Retire</x-eh.button></div>
                        </form>
                    </section>
                @endif

                <section class="ops-panel ops-panel--flush" aria-labelledby="hist-h">
                    <div class="ops-panel__head"><h2 id="hist-h">Version history</h2></div>
                    <table class="ops-table ops-table--compact">
                        <thead><tr><th>Version</th><th>State</th></tr></thead>
                        <tbody>
                            @foreach ($versions as $v)
                                <tr @if($v->id === $set->id) aria-current="true" style="background:var(--eh-accent-tint)" @endif>
                                    <td><a href="{{ route('ops.pricing.show', $v->id) }}">v{{ $v->version }}</a><span class="sub">{{ $v->effective_from ? $v->effective_from->format('d M Y') : 'not in force' }}{{ $v->retired_at ? ' to '.$v->retired_at->format('d M Y') : '' }}</span></td>
                                    <td>@include('ops.pricing.status', ['v' => $v])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </div>
</x-ops-layout>
