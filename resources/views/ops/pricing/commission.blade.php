<x-ops-layout title="Commission plans">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Pricing</h1><p class="ops-head__finding">Commission is taken on the trip fare only; tolls reach the driver at cost. Without a live plan, a driver's net is shown as not configured, never as zero.</p></div></header>
        @include('ops.pricing.tabs', ['active' => 'commission'])
        <div class="ops-split">
            <section class="ops-panel ops-panel--flush" aria-label="Commission plans">
                @if ($lineages->isEmpty())
                    <p class="ops-none" style="padding:var(--eh-space-5)">No commission plan exists.</p>
                @else
                    <div class="ops-tablewrap"><table class="ops-table">
                        <thead><tr><th>Plan</th><th>State</th><th>Applies to</th><th class="num">Rate</th><th class="num">Fixed</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($lineages as $versions)
                                @foreach ($versions as $v)
                                    <tr>
                                        <td><strong>{{ $v->name }}</strong> v{{ $v->version }}<span class="sub">{{ $v->country }} {{ $v->currency }}</span></td>
                                        <td>@include('ops.pricing.status', ['v' => $v])</td>
                                        <td>{{ $v->fleet_id ? 'Fleet '.($fleets[$v->fleet_id] ?? $v->fleet_id) : ($v->service_area_id ? ($areas[$v->service_area_id] ?? 'area') : 'Whole market') }}<span class="sub">{{ $v->ride_category_id ? ($categories[$v->ride_category_id] ?? '') : 'every category' }}</span></td>
                                        <td class="num">{{ $v->percent_bp / 100 }}%</td>
                                        <td class="num"><x-eh.money :minor="$v->fixed_minor" :currency="$v->currency" /></td>
                                        <td>
                                            @if ($canManage && $v->status === 'draft')
                                                <form data-ops-action method="post" action="{{ route('pricing.ops.commission-plans.publish', $v->id) }}" data-ops-confirm="Publish {{ $v->name }} v{{ $v->version }}? Drivers' splits use it from now." data-ops-done="Plan published." style="margin:0"><x-eh.button type="submit" size="sm">Publish</x-eh.button></form>
                                            @elseif ($canManage && $v->status === 'published' && $v->retired_at === null)
                                                <form data-ops-action method="post" action="{{ route('pricing.ops.commission-plans.retire', $v->id) }}" data-ops-confirm="Retire {{ $v->name }}?" data-ops-done="Plan retired." style="margin:0"><x-eh.button type="submit" size="sm" variant="ghost">Retire</x-eh.button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
            @if ($canManage)
                <section class="ops-panel" aria-labelledby="newplan-h">
                    <div class="ops-panel__head"><h2 id="newplan-h">New draft plan</h2></div>
                    <form class="ops-act" data-ops-action method="post" action="{{ route('pricing.ops.commission-plans.store') }}" data-ops-done="Draft plan created. Publish it from the list.">
                        <x-eh.field label="Name" name="name" required maxlength="120" id="cp-name" />
                        <x-eh.field label="Rate (basis points, 2000 = 20%)" name="percent_bp" type="number" min="0" max="5000" required id="cp-bp" />
                        <x-eh.field label="Fixed fee per trip (rand)" name="fixed_rand" inputmode="decimal" data-ops-minor="fixed_minor" value="0" id="cp-fixed" />
                        <x-eh.select label="Category" name="ride_category_id" :options="$categories->all()" placeholder="Every category" id="cp-cat" />
                        <x-eh.select label="Fleet" name="fleet_id" :options="$fleets->all()" placeholder="No fleet (market plan)" id="cp-fleet" />
                        <input type="hidden" name="country" value="ZA"><input type="hidden" name="currency" value="ZAR">
                        <div class="ops-act__row"><x-eh.button type="submit" size="sm">Create draft</x-eh.button></div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-ops-layout>
