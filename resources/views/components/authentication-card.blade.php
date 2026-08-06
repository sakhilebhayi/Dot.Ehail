<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-[var(--paper)]">
    <div>
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-[var(--panel)] border border-[var(--line)] rounded-2xl shadow-[0_30px_60px_-30px_rgba(27,32,26,0.25)] overflow-hidden">
        {{ $slot }}
    </div>
</div>
