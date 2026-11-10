{{-- Create or edit a ride policy. $policy is null for a new one. --}}
@php
    $p = $policy;
    $key = $p?->id ?? 'new';
    $rand = fn (?int $minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    $hm = fn (?int $m) => $m === null ? '' : \App\Domain\Business\Models\BusinessPolicy::minutesLabel($m);
    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $chosenDays = $p?->allowed_days ?? [];
    $chosenCats = $p?->allowed_categories ?? [];
    $chosenAreas = $p?->service_area_ids ?? [];
@endphp
<form class="bz-form" method="POST" action="{{ $p ? route('business.policies.update', $p->id) : route('business.policies.store') }}">
    @csrf
    @if ($p) @method('PATCH') @endif
    <div class="bz-form__row">
        <x-eh.field label="Policy name" name="name" :id="'pn-'.$key" :value="$p?->name" required />
        <x-eh.field label="Most per ride (R)" name="max_fare" :id="'pm-'.$key" :value="$rand($p?->max_fare_minor)" inputmode="decimal" optional hint="Rides above this are refused" />
    </div>
    <fieldset class="bz-checks">
        <legend>Categories allowed <span class="eh-muted">(none ticked means any)</span></legend>
        @forelse ($categories as $code => $label)
            <label class="bz-check"><input type="checkbox" name="allowed_categories[]" value="{{ $code }}" @checked(in_array($code, $chosenCats, true))>{{ $label }}</label>
        @empty
            <p class="bz-hint">No ride categories are set up yet; any category the platform offers is allowed.</p>
        @endforelse
    </fieldset>
    <fieldset class="bz-checks">
        <legend>Days <span class="eh-muted">(none ticked means every day)</span></legend>
        @foreach ($days as $n => $label)
            <label class="bz-check"><input type="checkbox" name="allowed_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', $chosenDays), true))>{{ $label }}</label>
        @endforeach
    </fieldset>
    <div class="bz-form__row">
        <x-eh.field label="From" name="hours_start" type="time" :id="'ph1-'.$key" :value="$hm($p?->hours_start)" optional hint="South African time; leave both empty for any time" />
        <x-eh.field label="Until" name="hours_end" type="time" :id="'ph2-'.$key" :value="$hm($p?->hours_end)" optional hint="An earlier time than From means overnight" />
    </div>
    @if ($areas->isNotEmpty())
        <fieldset class="bz-checks">
            <legend>Areas <span class="eh-muted">(pickup and drop-off must both be inside; none ticked means anywhere)</span></legend>
            @foreach ($areas as $area)
                <label class="bz-check"><input type="checkbox" name="service_area_ids[]" value="{{ $area->id }}" @checked(in_array($area->id, array_map('intval', $chosenAreas), true))>{{ $area->name }}</label>
            @endforeach
        </fieldset>
    @endif
    <div class="bz-form__row">
        <x-eh.field label="Approval needed above (R)" name="approval_above" :id="'pa-'.$key" :value="$rand($p?->approval_above_minor)" inputmode="decimal" optional hint="Approvers' own rides never need approval" />
        <x-eh.field label="Minutes an approver has to decide" name="approval_window_minutes" type="number" :id="'pw-'.$key" :value="$p?->approval_window_minutes ?? 30" min="5" max="1440" />
    </div>
    <input type="hidden" name="purpose_required" value="0">
    <x-eh.switch label="Purpose required" name="purpose_required" :id="'pp-'.$key" :checked="(bool) $p?->purpose_required" description="Travellers must say why they are riding; the purpose prints on the statement." />
    <input type="hidden" name="guest_bookings" value="0">
    <x-eh.switch label="Travellers may book for guests" name="guest_bookings" :id="'pg-'.$key" :checked="(bool) $p?->guest_bookings" description="Approvers can always book for guests. The guest's name and phone go to the driver." />
    <input type="hidden" name="is_default" value="0">
    <x-eh.switch label="Default policy" name="is_default" :id="'pd-'.$key" :checked="(bool) $p?->is_default" description="Applies to everyone without a policy of their own." />
    <div class="bz-form__actions"><x-eh.button type="submit" :icon="$p ? null : 'plus'">{{ $p ? 'Save policy' : 'Create policy' }}</x-eh.button></div>
</form>
