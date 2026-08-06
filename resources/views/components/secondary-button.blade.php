<button {{ $attributes->merge(['type' => 'button', 'class' => 'press inline-flex items-center px-5 py-2.5 bg-transparent border border-[var(--line)] rounded-full font-display font-medium text-sm text-[var(--ink)] hover:border-[var(--sage-bright)] focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 disabled:opacity-25 transition-colors']) }}>
    {{ $slot }}
</button>
