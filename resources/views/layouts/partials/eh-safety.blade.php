{{--
    Default safety control for the passenger and driver top bars until the wave-2 safety centre exists: a dialog with
    South Africa's emergency numbers as one-tap calls. It states plainly what it does; it raises no incident.
--}}
@if (Route::has($safetyRoute ?? 'ride.safety'))
    <a class="eh-safety-btn" href="{{ route($safetyRoute ?? 'ride.safety') }}"><x-eh.icon name="shield" />Safety</a>
@else
    <button type="button" class="eh-safety-btn" data-eh-dialog-open="eh-safety-dialog"><x-eh.icon name="shield" />Safety</button>
    <x-eh.dialog id="eh-safety-dialog" title="Emergency numbers">
        <p style="margin-top:0">If you are in danger, call for help now. These numbers reach South African emergency services directly; Dot.Ehail is not told when you use them.</p>
        <x-eh.banner tone="safety" title="One tap calls" />
        <p style="margin-bottom:0">In-ride safety tools (trip PIN, sharing your trip, SOS to our operations team) appear on every ride once ride booking opens here.</p>
    </x-eh.dialog>
@endif
