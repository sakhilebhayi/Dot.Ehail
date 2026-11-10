{{-- Offer alerts on this phone: sound and vibration switches, a test, and what a web app can and cannot do (D-2104).
     Used on the console (online) and on the account page. $headingLevel: 2 or 3. --}}
@php($h = 'h'.($headingLevel ?? 3))
@php($uid = $uid ?? 'dr-alerts')
<section class="dr-alertset {{ $class ?? '' }}" data-dr-alertset aria-labelledby="{{ $uid }}-h">
    <{{ $h }} class="dr-alertset__title" id="{{ $uid }}-h">Offer alerts on this phone</{{ $h }}>
    <div class="dr-alertset__row">
        <x-eh.switch label="Sound" name="sound" :id="$uid.'-sound'" :checked="true" />
        <x-eh.switch label="Vibrate" name="vibrate" :id="$uid.'-vibrate'" :checked="true" />
        <button type="button" class="eh-btn eh-btn--secondary eh-btn--md dr-alertset__test" data-dr-test-sound><x-eh.icon name="bell" />Test sound</button>
    </div>
    <p class="dr-note" data-dr-alert-test-result role="status"></p>
    <p class="dr-note" data-dr-vibrate-unsupported hidden>This browser cannot vibrate the phone (iPhones do not allow it for web apps). The sound and the screen still alert you.</p>
    <p class="dr-note" data-dr-sound-unsupported hidden>This browser cannot play sound for web apps. Offers still show on screen.</p>
    @php($limits = 'With the screen off, or another app in front, your phone may pause Dot.Ehail and offers cannot reach you. Add Dot.Ehail to your home screen (browser menu, Add to Home screen) so it is one tap away. Offers are never sent as push notifications: each one lasts '.(int) config('ehail_dispatch.offer_timeout_seconds', 15).' seconds.')
    @if ($compact ?? false)
        <details class="dr-alertset__more">
            <summary>Offers arrive while this screen is open</summary>
            <p class="dr-note">{{ $limits }}@if(Route::has('drive.notifications.settings')) Trip, payout and document notices can come as push notifications: <a class="dr-textlink dr-textlink--inline" href="{{ route('drive.notifications.settings') }}">notification settings</a>.@endif</p>
        </details>
    @else
        <p class="dr-note">Offers arrive while this screen is open. {{ $limits }}@if(Route::has('drive.notifications.settings')) Trip, payout and document notices can come as push notifications: <a class="dr-textlink dr-textlink--inline" href="{{ route('drive.notifications.settings') }}">notification settings</a>.@endif</p>
    @endif
    @if ($showWake ?? false)
        <p class="dr-note" data-dr-wake-status aria-live="polite"></p>
    @endif
</section>
