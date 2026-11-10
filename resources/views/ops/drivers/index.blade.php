<x-ops-layout title="Drivers">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Drivers</h1>
                <p class="ops-head__finding" @if(($counts['pending'] ?? 0) > 0) data-tone="caution" @endif>{{ number_format($counts['pending'] ?? 0) }} {{ \Illuminate\Support\Str::plural('application', $counts['pending'] ?? 0) }} pending, {{ number_format($counts['suspended'] ?? 0) }} suspended, {{ number_format($counts['approved'] ?? 0) }} approved.</p>
            </div>
            <div class="ops-head__actions">
                @can('ops.verification.review')<x-eh.button size="sm" variant="secondary" :href="route('ops.verification')" icon="licence-disc">Verification queue</x-eh.button>@endcan
            </div>
        </header>

        <form class="ops-filter" method="GET" action="{{ route('ops.drivers') }}" role="search" aria-label="Filter drivers">
            <x-eh.select label="Account" name="status" :options="['pending' => 'Application pending', 'approved' => 'Approved', 'suspended' => 'Suspended', 'rejected' => 'Rejected']" placeholder="Any" :value="$filters['status'] ?? ''" />
            <x-eh.select label="Right now" name="state" :options="['online' => 'Online', 'on_trip' => 'On a trip', 'paused' => 'Paused', 'offline' => 'Offline']" placeholder="Any" :value="$filters['state'] ?? ''" />
            <div class="eh-field eh-field--grow"><label class="eh-field__label" for="f-dq">Name or email</label><input id="f-dq" class="eh-input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80"></div>
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>

        <section class="ops-panel ops-panel--flush" aria-label="Drivers">
            @if ($profiles->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No driver matches these filters.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Driver</th><th>Account</th><th>Right now</th><th>Fleet</th><th class="num">Completed trips</th><th class="num">Applied</th></tr></thead>
                    <tbody>
                        @foreach ($profiles as $profile)
                            <tr>
                                <td><a href="{{ route('ops.drivers.show', $profile->user_id) }}">{{ $profile->user?->name ?? 'User '.$profile->user_id }}</a><span class="sub">{{ $profile->user?->email }}</span></td>
                                <td>@include('ops.partials.profile-status', ['status' => $profile->status, 'documents' => $documents[$profile->user_id] ?? null])</td>
                                <td>@include('ops.partials.state', ['row' => $availability[$profile->user_id] ?? null])</td>
                                <td>{{ $profile->fleet?->name ?? 'Fleet '.$profile->fleet_id }}</td>
                                <td class="num">{{ number_format((int) ($completed[$profile->user_id] ?? 0)) }}</td>
                                <td class="num">{{ $profile->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $profiles])
            @endif
        </section>
    </div>
</x-ops-layout>
