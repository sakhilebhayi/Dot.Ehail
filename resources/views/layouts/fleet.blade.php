{{-- Fleet operator shell (/fleet): owners of several cars and drivers. --}}
@include('layouts.partials.eh-side', [
    'surface' => 'fleet',
    'navLabel' => 'Fleet',
    'scopeTitle' => $scope ?? 'Your fleet',
    'slot' => $slot,
    'title' => $title ?? null,
    'header' => $header ?? null,
    'head' => $head ?? null,
])
