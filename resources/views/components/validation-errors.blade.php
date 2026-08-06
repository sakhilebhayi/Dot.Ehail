@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-rose-300 bg-rose-50 px-4 py-3']) }}>
        <div class="font-display font-medium text-sm text-rose-700">{{ __('Whoops! Something went wrong.') }}</div>

        <ul class="mt-2 list-disc list-inside text-sm text-rose-600">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
