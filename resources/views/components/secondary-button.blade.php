<button {{ $attributes->merge(['type' => 'button', 'class' => 'eh-btn eh-btn--secondary eh-btn--md']) }}>
    {{ $slot }}
</button>
