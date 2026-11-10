{{-- Toast region plus server flashes. Script: resources/js/eh/shell.js renders flashes and window.ehToast(). --}}
<div id="eh-toasts" class="eh-toasts" role="status" aria-live="polite"></div>
@foreach (['status' => 'neutral', 'success' => 'neutral', 'error' => 'stop'] as $flashKey => $flashTone)
    @if (session($flashKey) && is_string(session($flashKey)))
        <template data-eh-flash data-tone="{{ $flashTone }}">{{ session($flashKey) }}</template>
    @endif
@endforeach
@if (session('flash.banner'))
    <template data-eh-flash data-tone="{{ session('flash.bannerStyle') === 'danger' ? 'stop' : 'neutral' }}">{{ session('flash.banner') }}</template>
@endif
<noscript>
    @foreach (['status', 'success', 'error'] as $flashKey)
        @if (session($flashKey) && is_string(session($flashKey)))<p class="eh-banner" role="status">{{ session($flashKey) }}</p>@endif
    @endforeach
</noscript>
