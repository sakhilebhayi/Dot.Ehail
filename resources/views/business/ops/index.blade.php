<x-ops-layout title="Business accounts">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php($labels = ['pending_review' => 'Waiting for review', 'active' => 'Active', 'suspended' => 'Suspended', 'declined' => 'Declined'])
    <div class="bz">
        <div class="bz-title"><div class="bz-title__text">
            <h1 class="eh-h1" style="margin:0">Business accounts</h1>
            <p class="bz-title__sub">{{ (int) ($counts['pending_review'] ?? 0) }} waiting for review, {{ (int) ($counts['suspended'] ?? 0) }} suspended. Approving sets the credit limit and terms; nothing is billed to an account before that.</p>
        </div></div>
        <nav aria-label="Filter by state" style="display:flex;flex-wrap:wrap;gap:var(--eh-space-2)">
            <x-eh.chip :href="route('ops.business')" :current="$status === null">All</x-eh.chip>
            @foreach ($labels as $key => $label)
                <x-eh.chip :href="route('ops.business', ['status' => $key])" :current="$status === $key">{{ $label }} ({{ (int) ($counts[$key] ?? 0) }})</x-eh.chip>
            @endforeach
        </nav>
        <section class="bz-panel bz-panel--flush" aria-label="Accounts">
            @if ($accounts->isEmpty())
                <div style="padding:var(--eh-space-5)"><p class="bz-hint">No business accounts{{ $status ? ' in this state' : ' yet' }}.</p></div>
            @else
                <div class="bz-tablewrap"><table class="bz-table">
                    <thead><tr><th scope="col">Company</th><th scope="col">Registration</th><th scope="col">Applied</th><th scope="col">State</th><th scope="col" class="num">People</th><th scope="col" class="num">Credit limit</th><th scope="col" class="num">Owed</th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $a)
                            <tr>
                                <td><a class="eh-link" href="{{ route('ops.business.show', $a->id) }}">{{ $a->displayName() }}</a>@if ($a->trading_name)<span class="bz-table__sub">{{ $a->legal_name }}</span>@endif</td>
                                <td>{{ $a->registration_number }}</td>
                                <td>{{ $a->created_at->format('j M Y') }}</td>
                                <td><x-eh.badge :tone="$a->statusTone()">{{ $a->statusLabel() }}</x-eh.badge></td>
                                <td class="num">{{ $a->members_count }}</td>
                                <td class="num"><x-eh.money :minor="(int) $a->credit_limit_minor" :currency="$a->currency" /></td>
                                <td class="num"><x-eh.money :minor="$outstanding($a)" :currency="$a->currency" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
        @if ($accounts->hasPages())
            <nav class="bz-title__actions" aria-label="Pages">
                @if ($accounts->previousPageUrl())<x-eh.button variant="secondary" size="sm" :href="$accounts->previousPageUrl()">Previous</x-eh.button>@endif
                @if ($accounts->nextPageUrl())<x-eh.button variant="secondary" size="sm" :href="$accounts->nextPageUrl()">Next</x-eh.button>@endif
            </nav>
        @endif
    </div>
</x-ops-layout>
