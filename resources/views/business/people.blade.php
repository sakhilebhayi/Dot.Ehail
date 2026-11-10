<x-business-layout title="People" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php
        $cur = $account->currency;
        $rand = fn (?int $minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', '');
        $deptOptions = $departments->mapWithKeys(fn ($d) => [$d->id => $d->label()])->all();
        $policyOptions = $policies->mapWithKeys(fn ($p) => [$p->id => $p->name.($p->is_default ? ' (default)' : '')])->all();
        $active = $people->where('status', 'active');
        $invited = $people->where('status', 'invited');
    @endphp
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">People</h1>
                <p class="bz-title__sub">{{ $active->count() }} on the account, {{ $invited->count() }} invited. Allowances count rides billed and rides booked in {{ $monthLabel }}.</p>
            </div>
        </div>
        @include('business.partials.state')

        <section class="bz-panel bz-panel--flush" aria-labelledby="bz-people-title">
            <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-people-title">On the account</h2></div>
            <ul class="bz-rows">
                @foreach ($active as $person)
                    @php($used = (int) ($usage->get($person->id)?->spent_minor ?? 0) + (int) ($usage->get($person->id)?->reserved_minor ?? 0))
                    <li>
                        <details>
                            <summary>
                                <x-eh.avatar :name="$person->displayName()" :size="36" />
                                <span class="bz-rows__main">
                                    <p class="bz-rows__title">{{ $person->displayName() }}@if ($person->id === $member->id) <span class="eh-muted">(you)</span>@endif</p>
                                    <p class="bz-rows__meta">{{ $person->roleLabel() }} · {{ $person->department?->name ?? 'No department' }} · {{ $person->policy?->name ?? 'Default policy' }}@if ($person->employee_ref) · {{ $person->employee_ref }}@endif</p>
                                </span>
                                <span class="bz-rows__trail">
                                    @if ($person->monthly_allowance_minor !== null)
                                        <span><x-eh.money :minor="$used" :currency="$cur" /> of <x-eh.money :minor="$person->monthly_allowance_minor" :currency="$cur" /></span>
                                    @elseif ($person->role !== 'finance_viewer')
                                        <span class="eh-muted">No allowance cap</span>
                                    @else
                                        <span class="eh-muted">Does not ride</span>
                                    @endif
                                    <x-eh.icon name="chevron-down" />
                                </span>
                            </summary>
                            <div class="bz-rows__body">
                                <p class="bz-hint" style="margin-bottom:var(--eh-space-4)">{{ $person->user?->email ?? $person->email }} · joined {{ $person->joined_at?->format('j M Y') }}</p>
                                @if ($can['manage_people'] && ($member->role === 'account_admin' || $person->role === 'traveller'))
                                    <form class="bz-form" method="POST" action="{{ route('business.people.update', $person->id) }}">
                                        @csrf @method('PATCH')
                                        <div class="bz-form__row">
                                            @if (count($roles) > 1)<x-eh.select label="Role" name="role" :id="'role-'.$person->id" :options="$roles" :value="$person->role" />@endif
                                            <x-eh.select label="Department" name="department_id" :id="'dept-'.$person->id" :options="$deptOptions" :value="$person->department_id" placeholder="No department" />
                                        </div>
                                        <div class="bz-form__row bz-form__row--3">
                                            <x-eh.select label="Ride policy" name="policy_id" :id="'pol-'.$person->id" :options="$policyOptions" :value="$person->policy_id" placeholder="The default policy" />
                                            <x-eh.field label="Monthly allowance (R)" name="monthly_allowance" :id="'allow-'.$person->id" :value="$rand($person->monthly_allowance_minor)" inputmode="decimal" optional hint="Empty means no cap" />
                                            <x-eh.field label="Employee number" name="employee_ref" :id="'ref-'.$person->id" :value="$person->employee_ref" optional />
                                        </div>
                                        <div class="bz-form__actions">
                                            <x-eh.button type="submit" size="sm">Save {{ $person->displayName() }}</x-eh.button>
                                            @if ($person->id !== $member->id)
                                                <x-eh.button type="submit" size="sm" variant="ghost" icon="trash" form="remove-{{ $person->id }}">Remove from account</x-eh.button>
                                            @endif
                                        </div>
                                    </form>
                                    <form id="remove-{{ $person->id }}" method="POST" action="{{ route('business.people.destroy', $person->id) }}" data-eh-confirm="Remove {{ $person->displayName() }}? Their business profile stops working; trips already booked still complete.">@csrf @method('DELETE')</form>
                                @else
                                    <p class="bz-hint">Only account admins can change this person.</p>
                                @endif
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="bz-grid bz-grid--even">
            <section class="bz-panel" id="invite" aria-labelledby="bz-invite-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-invite-title">Invite someone</h2></div>
                @if ($can['manage_people'])
                    <form class="bz-form" method="POST" action="{{ route('business.people.invite') }}">
                        @csrf
                        <div class="bz-form__row">
                            <x-eh.field label="Work email" name="email" type="email" required hint="They sign in to Dot.Ehail with this address to accept." />
                            <x-eh.field label="Name" name="name" optional />
                        </div>
                        <div class="bz-form__row">
                            <x-eh.select label="Role" name="role" :options="$roles" value="traveller" />
                            <x-eh.select label="Department" name="department_id" :options="$deptOptions" placeholder="No department" />
                        </div>
                        <div class="bz-form__row">
                            <x-eh.select label="Ride policy" name="policy_id" :options="$policyOptions" placeholder="The default policy" />
                            <x-eh.field label="Monthly allowance (R)" name="monthly_allowance" inputmode="decimal" optional hint="Empty means no cap" />
                        </div>
                        <div class="bz-form__actions"><x-eh.button type="submit" icon="plus">Send invitation</x-eh.button></div>
                    </form>
                @else
                    <p class="bz-hint">Account admins and travel managers invite people.</p>
                @endif

                @if ($invited->isNotEmpty())
                    <h3 class="eh-h3" style="margin:var(--eh-space-6) 0 var(--eh-space-2)">Invited, not yet joined</h3>
                    <ul class="bz-queue">
                        @foreach ($invited as $person)
                            <li><div class="bz-queue__top">
                                <div class="bz-queue__who"><p class="bz-queue__name">{{ $person->email }}</p><p class="bz-queue__meta">{{ $person->roleLabel() }} · expires {{ $person->invite_expires_at?->format('j M') }}</p></div>
                                @if ($can['manage_people'])<form method="POST" action="{{ route('business.people.destroy', $person->id) }}" style="margin:0">@csrf @method('DELETE')<x-eh.button type="submit" size="sm" variant="ghost">Withdraw</x-eh.button></form>@endif
                            </div></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="bz-panel" id="departments" aria-labelledby="bz-dept-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-dept-title">Departments and cost centres</h2></div>
                @if ($departments->isEmpty())
                    <p class="bz-hint">No departments yet. Trips carry the department and cost centre they were booked under, so statements can be split.</p>
                @else
                    <ul class="bz-rows" style="margin:0 calc(-1 * var(--eh-space-5))">
                        @foreach ($departments as $d)
                            <li><details>
                                <summary>
                                    <x-eh.icon name="building" />
                                    <span class="bz-rows__main"><p class="bz-rows__title">{{ $d->name }}</p><p class="bz-rows__meta">{{ $d->cost_centre ?: 'No cost centre' }} · {{ $d->members_count }} {{ \Illuminate\Support\Str::plural('person', $d->members_count) }}</p></span>
                                    <span class="bz-rows__trail">@if ($d->monthly_budget_minor !== null)<span><x-eh.money :minor="$d->monthly_budget_minor" :currency="$cur" /> a month</span>@else<span class="eh-muted">No budget</span>@endif<x-eh.icon name="chevron-down" /></span>
                                </summary>
                                <div class="bz-rows__body">
                                    @if ($can['manage_departments'])
                                        <form class="bz-form" method="POST" action="{{ route('business.departments.update', $d->id) }}">
                                            @csrf @method('PATCH')
                                            <div class="bz-form__row bz-form__row--3">
                                                <x-eh.field label="Name" name="name" :id="'dn-'.$d->id" :value="$d->name" required />
                                                <x-eh.field label="Cost centre" name="cost_centre" :id="'dc-'.$d->id" :value="$d->cost_centre" optional />
                                                <x-eh.field label="Monthly budget (R)" name="monthly_budget" :id="'db-'.$d->id" :value="$rand($d->monthly_budget_minor)" inputmode="decimal" optional />
                                            </div>
                                            <div class="bz-form__actions">
                                                <x-eh.button type="submit" size="sm">Save</x-eh.button>
                                                <x-eh.button type="submit" size="sm" variant="ghost" icon="trash" form="dept-del-{{ $d->id }}">Remove</x-eh.button>
                                            </div>
                                        </form>
                                        <form id="dept-del-{{ $d->id }}" method="POST" action="{{ route('business.departments.destroy', $d->id) }}" data-eh-confirm="Remove the {{ $d->name }} department?">@csrf @method('DELETE')</form>
                                    @else
                                        <p class="bz-hint">Account admins change departments and budgets.</p>
                                    @endif
                                </div>
                            </details></li>
                        @endforeach
                    </ul>
                @endif
                @if ($can['manage_departments'])
                    <form class="bz-form" method="POST" action="{{ route('business.departments.store') }}" style="margin-top:var(--eh-space-5)">
                        @csrf
                        <div class="bz-form__row bz-form__row--3">
                            <x-eh.field label="New department" name="name" id="new-dept" required />
                            <x-eh.field label="Cost centre" name="cost_centre" id="new-dept-cc" optional />
                            <x-eh.field label="Monthly budget (R)" name="monthly_budget" id="new-dept-budget" inputmode="decimal" optional />
                        </div>
                        <div class="bz-form__actions"><x-eh.button type="submit" size="sm" variant="secondary" icon="plus">Add department</x-eh.button></div>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-business-layout>
