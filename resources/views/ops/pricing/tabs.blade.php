@php
    $items = [
        'tariffs' => ['label' => 'Tariffs', 'href' => route('ops.pricing')],
        'simulator' => ['label' => 'Simulator', 'href' => route('ops.pricing.simulator')],
        'promotions' => ['label' => 'Promotions', 'href' => route('ops.pricing.promotions')],
        'catalogue' => ['label' => 'Ride categories', 'href' => route('ops.pricing.catalogue')],
        'commission' => ['label' => 'Commission', 'href' => route('ops.pricing.commission')],
    ];
    if (app(\App\Domain\Ops\Access\OpsAccess::class)->allowsRoute(auth()->user(), 'ops.zones')) {
        $items['zones'] = ['label' => 'Service areas', 'href' => route('ops.zones')];
    }
@endphp
<x-eh.tabs class="ops-subnav" label="Pricing" :active="$active" :items="$items" />
