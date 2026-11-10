{{--
    SOS: press, then a 3-second countdown anyone can cancel, then the safety API raises the incident and the page
    moves to "what we did". The words say exactly what happens and what does not (docs/R46_SAFETY.md section 1).
--}}
<dialog id="pax-sos" class="eh-dialog" aria-labelledby="pax-sos-title" data-pax-sos data-ride="{{ $rideId }}" data-url="{{ route('safety.rides.sos', $rideId) }}" data-incident-url="{{ route('ride.safety.incident', ['incident' => '__ID__']) }}" data-location-url="{{ route('safety.incidents.location', ['incident' => '__ID__']) }}">
    <div class="eh-dialog__head">
        <h2 class="eh-dialog__title" id="pax-sos-title">Emergency</h2>
        <x-eh.icon-button icon="close" label="Close" data-eh-dialog-close />
    </div>
    <div class="eh-dialog__body pax-sos">
        <div data-sos-step="ask">
            <p style="margin-top:0"><strong>If you are in danger, call for help now.</strong> These numbers reach South African emergency services directly.</p>
            <ul class="pax-calls">
                <li><a href="tel:10111"><span>Police (SAPS)</span><strong>10111</strong></a></li>
                <li><a href="tel:10177"><span>Ambulance and fire</span><strong>10177</strong></a></li>
                <li><a href="tel:112"><span>Any emergency, from a mobile</span><strong>112</strong></a></li>
            </ul>
            <p>Sending an SOS alerts the Dot.Ehail operations team and emails your confirmed trusted contacts with a live trip link. Dot.Ehail does not call the police or an ambulance for you.</p>
            <x-eh.button variant="danger" :block="true" class="pax-sos__go" icon="sos" data-sos-start>Send SOS</x-eh.button>
        </div>
        <div data-sos-step="count" hidden>
            <p class="pax-sos__count" aria-hidden="true" data-sos-count>3</p>
            <p role="status" aria-live="assertive" data-sos-count-text style="text-align:center">Sending SOS in 3 seconds.</p>
            <x-eh.button variant="secondary" :block="true" size="lg" data-sos-cancel>Stop, do not send</x-eh.button>
        </div>
        <div data-sos-step="sending" hidden>
            <p role="status" aria-live="assertive">Sending SOS. Keep this screen open.</p>
        </div>
        <div data-sos-step="failed" hidden>
            <p role="alert" data-sos-error style="font-weight:600">The SOS could not be sent.</p>
            <p>Call 10111 or 112 now. You can try sending again.</p>
            <x-eh.button variant="danger" :block="true" data-sos-retry>Try again</x-eh.button>
        </div>
    </div>
</dialog>
