{{-- Business accounts shell (/business): company travel admins. --}}
@include('layouts.partials.eh-side', [
    'surface' => 'business',
    'navLabel' => 'Business account',
    'scopeTitle' => $scope ?? 'Business account',
    'slot' => $slot,
    'title' => $title ?? null,
    'header' => $header ?? null,
    'head' => $head ?? null,
])
