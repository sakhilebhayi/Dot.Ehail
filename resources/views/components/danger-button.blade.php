<button {{ $attributes->merge(['type' => 'button', 'class' => 'eh-btn eh-btn--danger eh-btn--md']) }}>
    {{ $slot }}
</button>
