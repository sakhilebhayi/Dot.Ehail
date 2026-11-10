@if ($errors->any())
    <x-eh.banner tone="stop" :title="__('Please check the form')" {{ $attributes }}>
        <ul style="margin:0;padding-left:1.1em">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-eh.banner>
@endif
