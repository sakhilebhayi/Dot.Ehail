<x-business-layout title="Ride policies" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">Ride policies</h1>
                <p class="bz-title__sub">Checked by Dot.Ehail when a ride is requested, before any driver is asked. A ride outside its policy is refused with the reason; a ride above the approval amount waits for an approver.</p>
            </div>
        </div>
        @include('business.partials.state')

        <section class="bz-panel bz-panel--flush" aria-labelledby="bz-pol-title">
            <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-pol-title">{{ $policies->count() }} {{ \Illuminate\Support\Str::plural('policy', $policies->count()) }}</h2></div>
            <ul class="bz-rows">
                @foreach ($policies as $policy)
                    <li><details @if ($loop->first && $policies->count() === 1) open @endif>
                        <summary>
                            <x-eh.icon name="document" />
                            <span class="bz-rows__main">
                                <p class="bz-rows__title">{{ $policy->name }} @if ($policy->is_default)<x-eh.badge tone="accent">Default</x-eh.badge>@endif</p>
                                <p class="bz-rows__meta">
                                    {{ $policy->allowed_categories ? implode(', ', array_map('ucfirst', $policy->allowed_categories)) : 'Any category' }}
                                    · {{ $policy->hasHours() ? $policy::minutesLabel($policy->hours_start).' to '.$policy::minutesLabel($policy->hours_end) : 'any time' }}
                                    · {{ $policy->max_fare_minor !== null ? 'up to '.\App\Support\Ui\MoneyFormatter::format($policy->max_fare_minor, $account->currency) : 'no fare cap' }}
                                    @if ($policy->approval_above_minor !== null) · approval above {{ \App\Support\Ui\MoneyFormatter::format($policy->approval_above_minor, $account->currency) }}@endif
                                    @if ($policy->purpose_required) · purpose required @endif
                                </p>
                            </span>
                            <span class="bz-rows__trail"><span>{{ (int) ($usage[$policy->id] ?? 0) + ($policy->is_default ? (int) ($usage[''] ?? 0) : 0) }} people</span><x-eh.icon name="chevron-down" /></span>
                        </summary>
                        <div class="bz-rows__body">
                            @if ($can['manage_policies'])
                                @include('business.partials.policy-form', ['policy' => $policy])
                            @else
                                @include('business.partials.policy-facts', ['policy' => $policy, 'cur' => $account->currency])
                            @endif
                        </div>
                    </details></li>
                @endforeach
            </ul>
        </section>

        @if ($can['manage_policies'])
            <section class="bz-panel" aria-labelledby="bz-newpol-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-newpol-title">New policy</h2><p class="bz-panel__caption">Give it to people on the People page</p></div>
                @include('business.partials.policy-form', ['policy' => null])
            </section>
        @endif
    </div>
</x-business-layout>
