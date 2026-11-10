<x-business-layout title="Invitation">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    <div class="bz" style="max-width:40rem">
        @if ($invitation === null)
            <x-eh.empty title="This invitation is no longer open">
                <p style="margin:0">It was already accepted or withdrawn. Ask the account admin to send a new one if you still need access.</p>
                <x-slot:actions><x-eh.button variant="secondary" :href="route('business.home')">Business home</x-eh.button></x-slot:actions>
            </x-eh.empty>
        @else
            <section class="bz-panel">
                <h1 class="eh-h1" style="margin:0 0 var(--eh-space-2)">Join {{ $invitation->account->displayName() }}</h1>
                <p class="bz-title__sub" style="margin-bottom:var(--eh-space-5)">You are invited as {{ strtolower($invitation->roleLabel()) }}. @if ($invitation->role !== 'finance_viewer') You will be able to request rides billed to the company, within its ride policy. @else You will be able to read trips and statements. @endif</p>
                @if ($expired)
                    <x-eh.banner tone="stop" title="This invitation has expired">Ask the account admin to send a new one.</x-eh.banner>
                @elseif (! $emailMatches)
                    <x-eh.banner tone="caution" title="Signed in with a different email">This invitation is for {{ $invitation->email }}. Sign out and sign in with that address to accept it.</x-eh.banner>
                @else
                    <form method="POST" action="{{ route('business.invitations.accept', $token) }}">@csrf<x-eh.button type="submit" icon="check">Accept and join</x-eh.button></form>
                @endif
            </section>
        @endif
    </div>
</x-business-layout>
