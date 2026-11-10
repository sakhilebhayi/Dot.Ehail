<x-eh.tabs class="ops-subnav" label="Rides" :active="$active" :items="[
    'desk' => ['label' => 'Desk', 'href' => route('ops.rides')],
    'all' => ['label' => 'All rides', 'href' => route('ops.rides', ['view' => 'all'])],
]" />
