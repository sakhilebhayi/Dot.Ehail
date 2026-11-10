{{-- Account state that changes what people can do, said once at the top of every business page. --}}
@if ($account->status === 'pending_review')
    <x-eh.banner tone="caution" title="Waiting for review by Dot.Ehail">
        Rides cannot be billed to {{ $account->displayName() }} until an operator approves the account and sets its credit terms. You can invite people and write ride policies meanwhile.
    </x-eh.banner>
@elseif ($account->status === 'suspended')
    <x-eh.banner tone="stop" title="New business rides are paused">
        {{ $account->status_reason === 'overdue' ? 'A statement is overdue.' : ($account->status_note ?: 'Dot.Ehail paused this account.') }}
        Trips already booked still complete and are billed. @if (($can['view_statements'] ?? false))<a class="eh-link" href="{{ route('business.invoices') }}">See statements</a>.@endif
    </x-eh.banner>
@elseif ($account->status === 'declined')
    <x-eh.banner tone="stop" title="This application was declined">
        {{ $account->status_note ?: 'Dot.Ehail did not approve this business account.' }}
    </x-eh.banner>
@endif
