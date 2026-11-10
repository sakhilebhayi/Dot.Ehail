{{-- Operations shell (/ops): dense desktop work surface with grouped left navigation. Operators only (route middleware). --}}
@include('layouts.partials.eh-side', [
    'surface' => 'ops',
    'navLabel' => 'Operations',
    'scopeTitle' => 'Operations',
    'scopeSub' => '',
    'dense' => true,
    'groups' => [
        'Now' => ['live', 'rides'],
        'Drivers' => ['drivers', 'verification'],
        'Care' => ['safety', 'support'],
        'Money' => ['pricing', 'finance'],
        'Accounts' => ['fleets', 'business'],
        'Learn' => ['insights'],
    ],
    'slot' => $slot,
    'title' => $title ?? null,
    'header' => $header ?? null,
    'head' => $head ?? null,
])
