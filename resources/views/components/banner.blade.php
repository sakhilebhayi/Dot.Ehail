@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

{{-- Jetstream flash banner (Livewire pages dispatch banner-message). Styled as an inline eh banner. --}}
<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
    class="eh-banner"
    :class="{ 'eh-banner--go': style == 'success', 'eh-banner--stop': style == 'danger', 'eh-banner--caution': style == 'warning' }"
    style="display: none; border-radius: 0"
    role="status"
    x-show="show && message"
    x-on:banner-message.window="
        style = event.detail.style;
        message = event.detail.message;
        show = true;
    ">
    <x-eh.icon name="info" />
    <div class="eh-banner__body"><p class="eh-banner__title" x-text="message"></p></div>
    <button type="button" class="eh-iconbtn" aria-label="Dismiss" x-on:click="show = false"><x-eh.icon name="close" /></button>
</div>
