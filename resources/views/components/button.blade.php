<button {{ $attributes->merge(['type' => 'submit', 'class' => 'eh-btn eh-btn--primary eh-btn--md']) }}>
    {{ $slot }}
</button>
