<button {{ $attributes->merge(['type' => 'submit', 'class' => 'press inline-flex items-center px-7 py-3 bg-[var(--ink)] hover:bg-[var(--sage)] text-white font-display font-semibold rounded-full focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 disabled:opacity-50 transition-colors']) }}>
    {{ $slot }}
</button>
