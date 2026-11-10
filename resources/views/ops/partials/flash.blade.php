{{-- Server outcomes from plain form posts (pre-R-46 desk actions and ops-owned actions). --}}
@if ($errors->any())
    <x-eh.banner tone="stop" title="That was not done" class="ops-flash" style="margin-bottom:var(--eh-space-4)">
        @foreach ($errors->all() as $error)<span style="display:block">{{ $error }}</span>@endforeach
    </x-eh.banner>
@endif
