<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Settings" :line="'Status: '.($fleet->isApproved() ? 'approved'.($fleet->approved_at ? ' on '.$fleet->approved_at->format('j M Y') : '') : 'waiting for review by operations').'.'">
    <div class="fl-grid">
        <div class="fl-stack">
            <section class="fl-panel" aria-labelledby="profile-title">
                <div class="fl-panel__head"><h2 id="profile-title">Fleet details</h2></div>
                <form method="POST" action="{{ route('fleet.settings.update') }}" class="fl-form">
                    @csrf @method('PATCH')
                    <x-eh.field label="Fleet name" name="name" :value="$fleet->name" />
                    <x-eh.select label="Kind" name="kind" :value="$fleet->kind" :options="['company' => 'A company with drivers', 'independent' => 'Just me (fleet of one)']" />
                    <x-eh.field label="Trading name" name="trading_name" :value="$fleet->trading_name" :optional="true" />
                    <x-eh.field label="Company registration number" name="registration_number" :value="$fleet->registration_number" :optional="true" />
                    <x-eh.field label="Base city" name="base_city" :value="$fleet->base_city" :optional="true" />
                    <x-eh.field label="Contact email" name="contact_email" type="email" :value="$fleet->contact_email" :optional="true" />
                    <x-eh.field label="Contact phone" name="contact_phone" type="tel" :value="$fleet->contact_phone" :optional="true" />
                    <x-eh.select label="Who is paid the drivers' earnings" name="payee_mode" :value="$fleet->payee_mode ?? ''"
                        :options="['' => 'Platform setting (now: '.($platformPayee === 'fleet_owner' ? 'the fleet owner' : 'each driver').')', 'driver' => 'Each driver, directly', 'fleet_owner' => 'The fleet owner, who pays the drivers']"
                        hint="Applies to earnings recognised from now on. The fleet owner can only be paid once the fleet is approved, and needs a payout bank account." />
                    <div class="fl-form__actions">@if ($canWrite)<x-eh.button type="submit">Save details</x-eh.button>@endif</div>
                </form>
            </section>
        </div>

        <aside class="fl-stack" aria-label="People">
            <section>
                <h2 class="fl-rail-title">Managers</h2>
                <div class="fl-panel">
                    <p class="fl-note">Managers see only the areas you give them, never identity documents. Members and these settings stay with you.</p>
                    @if ($managers->isNotEmpty())
                        <ul class="fl-items" style="margin-top: var(--eh-space-3)">
                            @foreach ($managers as $member)
                                <li style="grid-template-columns: minmax(0,1fr)">
                                    <div class="fl-stack fl-stack--tight">
                                        <div><p class="fl-items__title">{{ $member->user?->name }}</p><p class="fl-items__who">{{ $member->user?->email }}</p></div>
                                        <form method="POST" action="{{ route('fleet.settings.managers.update', $member->id) }}">
                                            @csrf @method('PATCH')
                                            <fieldset class="fl-checks">
                                                <legend class="eh-sr-only">Areas for {{ $member->user?->name }}</legend>
                                                @foreach ($scopes as $key => $label)
                                                    <label class="fl-check"><input type="checkbox" name="scopes[]" value="{{ $key }}" @checked(in_array($key, $member->scopes ?? [], true))><span>{{ $label }}</span></label>
                                                @endforeach
                                            </fieldset>
                                            <div class="fl-inline" style="margin-top: var(--eh-space-2)"><x-eh.button type="submit" size="sm" variant="secondary">Save areas</x-eh.button></div>
                                        </form>
                                        <form method="POST" action="{{ route('fleet.settings.managers.destroy', $member->id) }}" class="fl-inline">
                                            @csrf @method('DELETE')
                                            <x-eh.button type="submit" size="sm" variant="ghost">Remove {{ $member->user?->name }}</x-eh.button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <details class="fl-disclose" style="margin-top: var(--eh-space-3)" @if($errors->hasAny(['email', 'scopes'])) open @endif>
                        <summary><x-eh.icon name="plus" />Add a manager</summary>
                        <form method="POST" action="{{ route('fleet.settings.managers.store') }}" class="fl-stack fl-stack--tight">
                            @csrf
                            <x-eh.field label="Their Dot email" name="email" type="email" hint="They need to have signed in to Dot.Ehail once." />
                            <fieldset class="fl-checks">
                                <legend>Areas</legend>
                                @foreach ($scopes as $key => $label)
                                    <label class="fl-check"><input type="checkbox" name="scopes[]" value="{{ $key }}" @checked(in_array($key, old('scopes', ['vehicles', 'maintenance']), true))><span>{{ $label }}</span></label>
                                @endforeach
                                @error('scopes')<p class="eh-field__error">{{ $message }}</p>@enderror
                            </fieldset>
                            <div><x-eh.button type="submit" size="sm">Add manager</x-eh.button></div>
                        </form>
                    </details>
                </div>
            </section>
            <section class="fl-panel">
                <dl class="fl-kv">
                    <dt>Identity review</dt>
                    <dd>{{ $fleet->identityReviewGranted() ? 'Granted by operations on '.$fleet->identity_review_granted_at->format('j M Y').': you see masked licence and ID numbers on applications.' : 'Not granted: you see applicants\' names and verification status only. Operations check identity documents.' }}</dd>
                </dl>
            </section>
        </aside>
    </div>
</x-fleet.page>
