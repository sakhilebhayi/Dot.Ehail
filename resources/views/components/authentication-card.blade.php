<div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (night-time city street with a car, Luke Miller),
    with the same dark-ink scrim treatment the hero itself already proves works on this brand. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1482029255085-35a4a48b7084?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 68% 62% at 50% 40%, rgba(27,32,26,0.9) 0%, rgba(27,32,26,0.68) 45%, rgba(27,32,26,0.35) 74%, rgba(27,32,26,0.12) 100%);"></div>
    <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(27,32,26,0.6) 0%, transparent 18%, transparent 74%, rgba(27,32,26,0.5) 100%);"></div>

    <div class="relative z-10">
        {{ $logo }}
    </div>

    <div class="relative z-10 w-full sm:max-w-md mt-6 px-6 py-4 bg-[var(--panel)] border border-[var(--line)] rounded-2xl shadow-[0_30px_60px_-30px_rgba(0,0,0,0.5)] overflow-hidden">
        {{ $slot }}
    </div>
</div>
