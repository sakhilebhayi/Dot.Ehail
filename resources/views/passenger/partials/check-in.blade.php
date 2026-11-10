{{--
    "Are you OK?" after a route deviation or an unusual stop (safety check-ins). Two big answers; "I need help"
    raises an incident and alerts the person's trusted contacts, then shows what was done.
--}}
<dialog id="pax-checkin" class="eh-dialog pax-checkin" aria-labelledby="pax-checkin-title" data-pax-checkin data-list-url="{{ route('safety.check-ins.index') }}" data-answer-url="{{ route('safety.check-ins.respond', ['checkIn' => '__ID__']) }}" data-incident-url="{{ route('ride.safety.incident', ['incident' => '__ID__']) }}">
    <div class="eh-dialog__head">
        <h2 class="eh-dialog__title" id="pax-checkin-title">Are you OK?</h2>
    </div>
    <div class="eh-dialog__body">
        <p style="margin-top:0" data-checkin-why>Your trip left the planned route or stopped for a while.</p>
        <div class="pax-checkin__answers">
            <x-eh.button size="lg" :block="true" icon="check" data-checkin-answer="ok">Yes, I'm OK</x-eh.button>
            <x-eh.button variant="danger" size="lg" :block="true" icon="sos" data-checkin-answer="need_help">I need help</x-eh.button>
        </div>
        <p class="pax-rule" style="margin-top: var(--eh-space-3)">If nobody answers within a few minutes, our operations team is alerted. Your contacts are told only if you ask for help.</p>
        <p class="pax-field__status" data-kind="error" role="alert" data-checkin-error></p>
    </div>
</dialog>
