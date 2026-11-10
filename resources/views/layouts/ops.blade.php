{{-- Operations shell (/ops): dense desktop work surface with grouped left navigation. Operators only (route middleware).
     Each destination is shown only when the operator's role allows its route (R-46 ops: App\Domain\Ops\Access). --}}
@php
    $opsGroups = [
        'Now' => ['live', 'rides'],
        'Drivers' => ['drivers', 'verification'],
        'Care' => ['safety', 'support'],
        'Money' => ['pricing', 'finance'],
        'Accounts' => ['fleets', 'business'],
        'Learn' => ['insights'],
        'Admin' => ['audit', 'team'],
    ];
    $opsUser = auth()->user();
    if (class_exists(\App\Domain\Ops\Access\OpsAccess::class) && $opsUser) {
        $opsAccess = app(\App\Domain\Ops\Access\OpsAccess::class);
        $opsRoutes = collect(\App\Support\Ui\Navigation::SURFACES['ops'])->mapWithKeys(fn ($item) => [$item['key'] => collect($item['routes'])->first(fn ($route) => Route::has($route))]);
        foreach ($opsGroups as $label => $keys) {
            $opsGroups[$label] = array_values(array_filter($keys, fn ($key) => ($route = $opsRoutes[$key] ?? null) !== null && $opsAccess->allowsRoute($opsUser, $route)));
        }
    }
@endphp
@include('layouts.partials.eh-side', [
    'surface' => 'ops',
    'navLabel' => 'Operations',
    'scopeTitle' => 'Operations',
    'scopeSub' => '',
    'dense' => true,
    'groups' => $opsGroups,
    'slot' => $slot,
    'title' => $title ?? null,
    'header' => $header ?? null,
    'head' => $head ?? null,
])
