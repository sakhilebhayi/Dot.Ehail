<x-eh.tabs class="ops-subnav" label="Verification" :active="$active" :items="[
    'driver' => ['label' => 'Driver documents ('.($pendingBySubject['driver'] ?? 0).')', 'href' => route('ops.verification')],
    'vehicle' => ['label' => 'Vehicle documents ('.($pendingBySubject['vehicle'] ?? 0).')', 'href' => route('ops.verification', ['subject' => 'vehicle'])],
    'applications' => ['label' => 'Applications ('.$applications.')', 'href' => route('ops.verification', ['subject' => 'applications'])],
    'expiry' => ['label' => 'Expiry', 'href' => route('ops.verification.expiry')],
]" />
