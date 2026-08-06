<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Ehail — Run your own e-hailing business</title>
        <meta name="description" content="Onboard drivers, register vehicles, take ride requests, dispatch, track trips in real time, settle fares, and collect ratings. The operator platform for e-hailing businesses of any size.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600;700&family=Overpass+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #f6f5ef;
                --panel: #fdfcf8;
                --ink: #1b201a;
                --ink-soft: #565c53;
                --gold: #c99626;
                --gold-bright: #e2b13d;
                --sage: #5c8447;
                --sage-bright: #7fa863;
                --line: rgba(27, 32, 26, 0.12);
                --font-display: 'Space Grotesk', ui-sans-serif, sans-serif;
                --font-body: 'Manrope', system-ui, sans-serif;
                --font-mono: 'Overpass Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
                .draw-path {
                    stroke-dasharray: 900;
                    stroke-dashoffset: 900;
                    transition: stroke-dashoffset 1400ms var(--ease-out);
                }
                .draw-path.is-visible { stroke-dashoffset: 0; }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
                .draw-path { stroke-dashoffset: 0; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(27, 32, 26, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#f6f5ef]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#fleet" class="link-underline hover:text-[var(--ink)] pb-0.5">Fleet</a>
                    <a href="#dashboard" class="link-underline hover:text-[var(--ink)] pb-0.5">Dashboard</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--sage)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--sage)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#fleet" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Fleet</a>
                    <a href="#dashboard" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Dashboard</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative pt-32 pb-16 sm:pb-24 px-5 sm:px-8 overflow-hidden">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                            E-hailing operator platform
                        </p>
                        <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--ink)] mb-6">
                            Your fleet.<br>Your brand.<br>Your rides.
                        </h1>
                        <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                            Dot.Ehail lets an operator — a single owner-driver or a multi-vehicle fleet — run a branded e-hailing business: onboard drivers, register vehicles, take ride requests, dispatch, track trips in real time, settle fares, and collect ratings.
                        </p>

                        @guest
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--sage)] text-white font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                                <a href="#dashboard" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--sage-bright)] transition-colors">
                                    See the dashboard
                                </a>
                            </div>
                        @endguest
                    </div>

                    <!-- Signature element: the ride lifecycle from the real Ride.status column, drawn as a route with a car marker echoing the logo's car icon -->
                    <div class="reveal" data-reveal>
                        <div class="relative rounded-[2rem] border border-[var(--line)] bg-[var(--panel)] p-6 sm:p-8 shadow-[0_30px_60px_-30px_rgba(27,32,26,0.25)]">
                            <div class="flex items-center justify-between mb-6 font-mono text-[11px] tracking-[0.12em] uppercase text-[var(--ink-soft)]">
                                <span>Ride #4471</span>
                                <span>En route</span>
                            </div>

                            <svg viewBox="0 0 400 220" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto" aria-hidden="true">
                                <rect x="0.5" y="0.5" width="399" height="219" rx="14" stroke="var(--line)" stroke-dasharray="4 4"/>
                                <path
                                    class="draw-path"
                                    data-reveal
                                    d="M35 150 C 90 150, 90 60, 145 60 C 200 60, 200 150, 255 150 C 310 150, 310 60, 365 60"
                                    stroke="var(--sage)"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                />
                                <circle cx="35" cy="150" r="5" fill="var(--ink)"/>
                                <circle cx="145" cy="60" r="5" fill="var(--panel)" stroke="var(--ink-soft)" stroke-width="2"/>
                                <circle cx="255" cy="150" r="5" fill="var(--panel)" stroke="var(--ink-soft)" stroke-width="2"/>
                                <circle cx="365" cy="60" r="6" fill="var(--gold-bright)"/>
                                <!-- car marker at the "en_route" waypoint -->
                                <g transform="translate(200, 60)">
                                    <rect x="-16" y="-8" width="32" height="14" rx="4" fill="var(--ink)"/>
                                    <path d="M-10 -8 L-5 -15 L9 -15 L14 -8 Z" fill="var(--ink)"/>
                                    <circle cx="-8" cy="7" r="4" fill="var(--panel)" stroke="var(--ink)" stroke-width="2"/>
                                    <circle cx="8" cy="7" r="4" fill="var(--panel)" stroke="var(--ink)" stroke-width="2"/>
                                </g>
                            </svg>

                            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-6 pt-6 border-t border-[var(--line)] font-mono text-[10px] tracking-wide uppercase text-[var(--ink-soft)]">
                                <span>Requested</span>
                                <span class="text-[var(--sage)]">→</span>
                                <span>Accepted</span>
                                <span class="text-[var(--sage)]">→</span>
                                <span class="text-[var(--ink)] font-semibold">En route</span>
                                <span class="text-[var(--sage)]">→</span>
                                <span>Arrived</span>
                                <span class="text-[var(--sage)]">→</span>
                                <span>In progress</span>
                                <span class="text-[var(--sage)]">→</span>
                                <span>Completed</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- What you're running -->
        <section id="fleet" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">What you're running</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        A real driver-vehicle-ride-rating model, not a demo
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $entities = [
                            ['tag' => 'Drivers', 'title' => 'Driver profiles', 'body' => 'Onboarding status from pending to approved or suspended, online availability, rolling rating, and total-rides count — one profile per platform user.'],
                            ['tag' => 'Vehicles', 'title' => 'Vehicles', 'body' => 'Make, model, year, color, and plate, tiered as economy, standard, premium, or SUV. Each driver has one active vehicle at a time.'],
                            ['tag' => 'Rides', 'title' => 'Rides', 'body' => 'Pickup and dropoff address with coordinates, a six-stage lifecycle from requested to completed or cancelled, estimated and final fare, and distance.'],
                            ['tag' => 'Ratings', 'title' => 'Ride ratings', 'body' => 'One rating per ride, rater-agnostic — either the passenger or the driver can be the one who rates the trip.'],
                        ];
                    @endphp
                    @foreach ($entities as $i => $e)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--sage)] mb-3">{{ $e['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $e['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{{ $e['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Dashboard -->
        <section id="dashboard" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-bright)] mb-4">Live from day one</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-white leading-tight mb-5">
                            A real operations view, not a mock
                        </h2>
                        <p class="text-[#c6c9c3] leading-relaxed max-w-sm">
                            The dashboard computes live from your own database the moment you sign in — every number below is a real query, not a placeholder.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $dashboard = [
                                ['title' => 'Ride counts by lifecycle bucket', 'body' => 'Total, active, completed, cancelled, and requested — grouped straight from Ride.status.'],
                                ['title' => 'Driver counts', 'body' => 'Total, currently online, and approved drivers, updated as profiles change status.'],
                                ['title' => 'Revenue', 'body' => 'Sum of final fares across every completed ride.'],
                                ['title' => 'Recent rides feed', 'body' => 'The ten most recent rides, with driver, passenger, and vehicle already loaded in.'],
                            ];
                        @endphp
                        @foreach ($dashboard as $d)
                            <div class="py-6 border-t border-[rgba(255,255,255,0.14)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-white mb-1.5">{{ $d['title'] }}</h3>
                                <p class="text-sm text-[#c6c9c3] leading-relaxed">{{ $d['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- Ecosystem -->
        <section class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--panel)] border-b border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">Part of the Dot Ecosystem</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Built on the same account, the same infrastructure
                    </h2>
                </div>

                <div class="grid md:grid-cols-3 border-t border-[var(--line)]">
                    @php
                        $capabilities = [
                            ['tag' => 'Accounts', 'title' => 'Team-based accounts', 'body' => 'Jetstream teams and Sanctum auth — the same account model as every other Dot platform.'],
                            ['tag' => 'SSO', 'title' => 'Ecosystem single sign-on', 'body' => 'A one-time Sanctum token minted elsewhere in the ecosystem logs you straight into your dashboard.'],
                            ['tag' => 'Data', 'title' => 'Shared PostgreSQL', 'body' => 'Driver, vehicle, ride, and rating records run on the same infrastructure as the rest of the Dot Ecosystem.'],
                        ];
                    @endphp
                    @foreach ($capabilities as $i => $c)
                        <div class="row-hover border-b border-[var(--line)] {{ $i < 2 ? 'md:border-r' : '' }} px-1 py-8 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--sage)] mb-3">{{ $c['tag'] }}</p>
                            <h3 class="font-display font-semibold text-lg text-[var(--ink)] mb-2">{{ $c['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $c['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    Onboard your first driver today
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in with your Dot Ecosystem account or create one to start registering drivers and vehicles.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--ink)] hover:bg-[var(--sage)] text-white font-display font-semibold rounded-full transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--sage-bright)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Ehail. E-hailing operator platform for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
