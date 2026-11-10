{{--
    Top-bar notifications link with the unread count (server-rendered; no polling). Include it in a shell's top bar:
    <x-comms::bell surface="passenger" />   (surface: passenger | driver | ops)
--}}
@props(['surface' => 'passenger'])
@php
    $bellUser = auth()->user();
    $bellRoute = (\App\Http\Controllers\Ehail\Comms\CommsPagesController::SURFACE_ROUTES[$surface] ?? 'ride').'.notifications';
    $bellCount = $bellUser ? $bellUser->unreadNotifications()->count() : 0;
    $bellLabel = $bellCount === 0 ? 'Notifications, none unread' : 'Notifications, '.$bellCount.' unread';
@endphp
@if ($bellUser && Route::has($bellRoute))
<a href="{{ route($bellRoute) }}" {{ $attributes->class('eh-iconbtn eh-iconbtn--ghost comms-bell') }} aria-label="{{ $bellLabel }}" title="{{ $bellLabel }}"
   @if(request()->routeIs($bellRoute, $bellRoute.'.*')) aria-current="page" @endif>
    <x-eh.icon name="bell" />
    @if ($bellCount > 0)<span class="comms-bell__count" aria-hidden="true">{{ $bellCount > 9 ? '9+' : $bellCount }}</span>@endif
</a>
@endif
