<x-fleet.page title="Register a fleet" line="A platform operator reviews every new fleet before its drivers can be approved. Until then you can set up its vehicles.">
    <form method="POST" action="{{ route('fleet.register.store') }}" class="fl-panel">
        @csrf
        <div class="fl-form">
            <fieldset class="fl-checks fl-form__full">
                <legend>What kind of fleet is it?</legend>
                <label class="fl-check"><input type="radio" name="kind" value="company" @checked(old('kind', 'company') === 'company')><span><strong>A company</strong> with several vehicles and drivers, and possibly managers.</span></label>
                <label class="fl-check"><input type="radio" name="kind" value="independent" @checked(old('kind') === 'independent')><span><strong>Just me</strong>: I drive my own vehicle and want my documents, maintenance and earnings in one place. Nothing here is required of you that you do not use.</span></label>
                @error('kind')<p class="eh-field__error">{{ $message }}</p>@enderror
            </fieldset>
            <x-eh.field label="Fleet name" name="name" hint="The name drivers see when they apply to join." />
            <x-eh.field label="Trading name" name="trading_name" :optional="true" />
            <x-eh.field label="Company registration number" name="registration_number" :optional="true" hint="CIPC number, for example 2021/123456/07." />
            <x-eh.field label="Base city" name="base_city" :optional="true" />
            <x-eh.field label="Contact email" name="contact_email" type="email" :optional="true" />
            <x-eh.field label="Contact phone" name="contact_phone" type="tel" :optional="true" />
            <p class="fl-note fl-form__full">Operating licences are issued per vehicle in South Africa. The driver who has a vehicle uploads its operating licence, licence disc, roadworthy and insurance in the driver app; our operations team verifies them, and you follow their status and expiry dates here.</p>
            <div class="fl-form__actions">
                <x-eh.button type="submit" icon="fleet">Register fleet</x-eh.button>
                <x-eh.button :href="route('fleet.home')" variant="ghost">Cancel</x-eh.button>
            </div>
        </div>
    </form>
</x-fleet.page>
