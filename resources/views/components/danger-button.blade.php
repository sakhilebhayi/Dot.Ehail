<button {{ $attributes->merge(['type' => 'button', 'class' => 'press inline-flex items-center justify-center px-5 py-2.5 bg-red-600 border border-transparent rounded-lg font-display font-semibold text-sm text-white hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors']) }}>
    {{ $slot }}
</button>
