@php($legacy = $operators->where('legacy', true)->count())
<x-ops-layout title="Team and roles">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Team and roles</h1>
                <p class="ops-head__finding" @if($legacy > 0) data-tone="caution" @endif>{{ $operators->count() }} {{ \Illuminate\Support\Str::plural('operator', $operators->count()) }}.@if($legacy > 0) {{ $legacy }} still {{ $legacy === 1 ? 'has' : 'have' }} full access from before roles existed: give each the roles they need.@endif</p>
            </div>
        </header>
        @include('ops.partials.flash')
        <div class="ops-split">
            <section class="ops-panel ops-panel--flush" aria-labelledby="team-h">
                <div class="ops-panel__head"><h2 id="team-h">Operators</h2><span class="ops-panel__meta">every change is in the audit log</span></div>
                <ul class="ops-queue">
                    @foreach ($operators as $op)
                        <li style="grid-template-columns:minmax(0,1fr)">
                            <span class="ops-queue__title">{{ $op['user']->name }} @if($op['legacy'])<x-eh.badge tone="caution">Full access (no roles yet)</x-eh.badge>@endif</span>
                            <span class="ops-queue__meta">{{ $op['user']->email }}</span>
                            <form method="POST" action="{{ route('ops.roles.update', $op['user']->id) }}" class="ops-act" style="margin-top:var(--eh-space-2)">
                                @csrf @method('PUT')
                                <fieldset style="border:0;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:var(--eh-space-1) var(--eh-space-4)">
                                    <legend class="eh-sr">Roles for {{ $op['user']->name }}</legend>
                                    @foreach ($roles as $role)
                                        <label class="ops-check"><input type="checkbox" name="roles[]" value="{{ $role->value }}" @checked(in_array($role->value, $op['roles'], true))> {{ $role->label() }}</label>
                                    @endforeach
                                </fieldset>
                                <div class="ops-act__row">
                                    <x-eh.button type="submit" size="sm" variant="secondary">Save roles</x-eh.button>
                                </div>
                            </form>
                            @if ($op['user']->id !== auth()->id())
                                <form method="POST" action="{{ route('ops.roles.destroy', $op['user']->id) }}" data-ops-confirm="Remove {{ $op['user']->name }}'s operations access?" style="margin:var(--eh-space-1) 0 0">
                                    @csrf @method('DELETE')
                                    <x-eh.button type="submit" size="sm" variant="ghost">Remove operator access</x-eh.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="ops-panel" aria-labelledby="add-h">
                <div class="ops-panel__head"><h2 id="add-h">Add an operator</h2></div>
                <form method="POST" action="{{ route('ops.roles.store') }}" class="ops-act">
                    @csrf
                    <x-eh.field label="Their email (they must have signed in once)" name="email" type="email" required id="op-email" />
                    <fieldset style="border:0;padding:0;margin:0;display:grid;gap:var(--eh-space-1)">
                        <legend class="eh-field__label" style="margin-bottom:var(--eh-space-2)">Roles</legend>
                        @foreach ($roles as $role)
                            <label class="ops-check"><input type="checkbox" name="roles[]" value="{{ $role->value }}"> <span><strong>{{ $role->label() }}</strong><br><span class="ops-stamp">{{ $role->summary() }}</span></span></label>
                        @endforeach
                    </fieldset>
                    <div class="ops-act__row"><x-eh.button type="submit" size="sm">Give access</x-eh.button></div>
                </form>
            </section>
        </div>
        <section class="ops-panel ops-panel--flush" aria-labelledby="matrix-h" style="margin-top:var(--ops-gap)">
            <div class="ops-panel__head"><h2 id="matrix-h">What each role can do</h2></div>
            <div class="ops-tablewrap"><table class="ops-table ops-table--compact ops-matrix">
                <thead><tr><th>Permission</th>@foreach ($roles as $role)<th class="num" style="text-align:center">{{ $role->label() }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($permissions as $perm => $desc)
                        <tr><th scope="row">{{ $desc }}</th>@foreach ($roles as $role)<td>@if(in_array($perm, $role->permissions(), true))<span class="ops-yes" aria-label="yes">Yes</span>@else<span class="ops-dash" aria-label="no">-</span>@endif</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    </div>
</x-ops-layout>
