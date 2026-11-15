{{--
    The notifications link with the unread count (server-rendered; no polling), printed as the shared dot-ui bell (R-52). The shells' header
    prints it: <x-comms::bell surface="passenger" />   (surface: passenger | driver | ops). A link bell: from 480px it sits in the bar, and under
    480px the account menu and the drawer carry the same link with the count.
--}}
@props(['surface' => 'passenger'])
@php
    $bellUser = auth()->user();
    $bellRoute = (\App\Http\Controllers\Ehail\Comms\CommsPagesController::SURFACE_ROUTES[$surface] ?? 'ride').'.notifications';
    $bellCount = $bellUser ? \App\Domain\Comms\Inbox::countFor($bellUser) : 0;
@endphp
@if ($bellUser && Route::has($bellRoute))
<x-dot.bell :href="route($bellRoute)" :count="$bellCount" :max="9" {{ $attributes->class('comms-bell') }} :aria-current="request()->routeIs($bellRoute, $bellRoute.'.*') ? 'page' : null" />
@endif
