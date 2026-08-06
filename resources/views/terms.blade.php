<x-guest-layout>
    <div class="pt-4 bg-[var(--paper)]">
        <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
            <div>
                <x-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-6 p-6 bg-[var(--panel)] border border-[var(--line)] rounded-2xl shadow-[0_30px_60px_-30px_rgba(27,32,26,0.25)] overflow-hidden prose prose-headings:font-display prose-headings:text-[var(--ink)] prose-p:text-[var(--ink-soft)] prose-li:text-[var(--ink-soft)] prose-strong:text-[var(--ink)] prose-a:text-[var(--gold)]">
                {!! $terms !!}
            </div>
        </div>
    </div>
</x-guest-layout>
