@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'eh-field__error']) }}><x-eh.icon name="alert" />{{ $message }}</p>
@enderror
