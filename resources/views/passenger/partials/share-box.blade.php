{{--
    Trip share sheet (resources/js/passenger/safety.js bindShare): the link once the server made it, Copy, Share on
    WhatsApp (a wa.me link with the trip link and a plain message; WhatsApp opens with the text ready, nothing is sent
    until the person sends it), the phone's own share sheet where the browser has one, and Revoke.
    heading: h2 | h3 (the page's outline).
--}}
@php($heading = in_array($heading ?? 'h2', ['h2', 'h3'], true) ? $heading : 'h2')
<section class="pax-section pax-share" data-pax-share-box hidden aria-labelledby="pax-share-title">
    <{{ $heading }} class="pax-h3" id="pax-share-title">Share your trip live</{{ $heading }}>
    <p class="pax-rule">Anyone with this link sees your first name, your driver's first name, the car and its plate, where you are heading and the car's approximate position, until the trip ends. Revoke it any time.</p>
    <div class="pax-share__link">
        <label class="eh-sr-only" for="pax-share-url">Trip link</label>
        <input class="pax-share__url" id="pax-share-url" readonly data-pax-share-url>
        <button type="button" class="eh-btn eh-btn--secondary eh-btn--sm" data-pax-share-copy><x-eh.icon name="copy" /><span class="eh-btn__label">Copy</span></button>
    </div>
    <div class="pax-share__send">
        <a class="eh-btn eh-btn--primary eh-btn--md" href="https://wa.me/" target="_blank" rel="noopener noreferrer" data-pax-share-whatsapp><x-eh.icon name="chat" /><span class="eh-btn__label">Share on WhatsApp</span></a>
        <button type="button" class="eh-btn eh-btn--secondary eh-btn--md" data-pax-share-native hidden><x-eh.icon name="share" /><span class="eh-btn__label">More apps</span></button>
    </div>
    <button type="button" class="eh-textlink pax-share__revoke" data-pax-share-revoke><x-eh.icon name="close" />Revoke the link</button>
</section>
