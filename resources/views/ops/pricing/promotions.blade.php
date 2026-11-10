@php($active = $promotions->where('is_active', true))
<x-ops-layout title="Promotions">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Pricing</h1><p class="ops-head__finding">{{ $active->count() }} active {{ \Illuminate\Support\Str::plural('promotion', $active->count()) }}. Discounts are funded by the platform; drivers earn on the full fare.</p></div></header>
        @include('ops.pricing.tabs', ['active' => 'promotions'])
        <div class="ops-split">
            <section class="ops-panel ops-panel--flush" aria-label="Promotions">
                @if ($promotions->isEmpty())
                    <p class="ops-none" style="padding:var(--eh-space-5)">No promotion has been created.</p>
                @else
                    <div class="ops-tablewrap"><table class="ops-table">
                        <thead><tr><th>Promotion</th><th>Discount</th><th>Window</th><th class="num">Used</th><th class="num">Budget spent</th><th><span class="eh-sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($promotions as $p)
                                <tr>
                                    <td><strong>{{ $p->name }}</strong><span class="sub">{{ $p->kind === 'code' ? 'Code '.$p->code : 'Automatic' }} · {{ $p->is_active ? 'active' : 'off' }}{{ ($p->eligibility['first_ride_only'] ?? false) ? ' · first ride only' : '' }}{{ !empty($p->eligibility['category_keys']) ? ' · '.implode(', ', $p->eligibility['category_keys']) : '' }}</span></td>
                                    <td>@if($p->discount_type === 'percent'){{ $p->percent_bp / 100 }}%@if($p->max_discount_minor) up to <x-eh.money :minor="$p->max_discount_minor" :currency="$p->currency" />@endif @else<x-eh.money :minor="(int) $p->amount_minor" :currency="$p->currency" />@endif</td>
                                    <td>{{ $p->starts_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') ?? 'now' }} to {{ $p->ends_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') ?? 'no end' }}</td>
                                    <td class="num">{{ $p->redemptions_count }}{{ $p->max_redemptions ? ' / '.$p->max_redemptions : '' }}</td>
                                    <td class="num"><x-eh.money :minor="(int) $p->spent_minor" :currency="$p->currency" />@if($p->budget_minor)<span class="sub">of <x-eh.money :minor="$p->budget_minor" :currency="$p->currency" /></span>@endif</td>
                                    <td>@if($canManage && $p->is_active)<form data-ops-action method="post" action="{{ route('pricing.ops.promotions.deactivate', $p->id) }}" data-ops-confirm="Switch off {{ $p->name }}? Quotes stop applying it at once." data-ops-done="Promotion switched off." style="margin:0"><x-eh.button type="submit" size="sm" variant="ghost">Switch off</x-eh.button></form>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
            @if ($canManage)
                <section class="ops-panel" aria-labelledby="newpromo-h">
                    <div class="ops-panel__head"><h2 id="newpromo-h">New promotion</h2></div>
                    <form class="ops-act" data-ops-action method="post" action="{{ route('pricing.ops.promotions.store') }}" data-ops-done="Promotion created.">
                        <x-eh.field label="Name" name="name" required maxlength="120" id="pr-name" />
                        <x-eh.select label="How it applies" name="kind" :options="['code' => 'With a code', 'automatic' => 'Automatically']" id="pr-kind" />
                        <x-eh.field label="Code (for code promotions)" name="code" maxlength="40" id="pr-code" :optional="true" />
                        <x-eh.select label="Discount" name="discount_type" :options="['percent' => 'Percentage', 'fixed' => 'Fixed amount']" id="pr-type" />
                        <x-eh.field label="Percentage (basis points, 1000 = 10%)" name="percent_bp" type="number" min="1" max="10000" id="pr-bp" :optional="true" />
                        <x-eh.field label="Fixed amount (rand)" name="amount_rand" inputmode="decimal" data-ops-minor="amount_minor" id="pr-amt" :optional="true" />
                        <x-eh.field label="Largest discount per ride (rand)" name="max_discount_rand" inputmode="decimal" data-ops-minor="max_discount_minor" id="pr-max" :optional="true" />
                        <x-eh.field label="Total budget (rand)" name="budget_rand" inputmode="decimal" data-ops-minor="budget_minor" id="pr-budget" :optional="true" />
                        <x-eh.field label="Uses per passenger" name="per_user_limit" type="number" min="1" max="100" value="1" id="pr-per" />
                        <x-eh.field label="Starts" name="starts_at" type="date" id="pr-start" :optional="true" />
                        <x-eh.field label="Ends" name="ends_at" type="date" id="pr-end" :optional="true" />
                        <input type="hidden" name="currency" value="ZAR">
                        <label class="ops-check"><input type="checkbox" name="eligibility[first_ride_only]" value="1"> First ride only</label>
                        <div class="ops-act__row"><x-eh.button type="submit" size="sm">Create promotion</x-eh.button></div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-ops-layout>
