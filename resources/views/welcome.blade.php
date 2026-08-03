<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Ehail - Ride-Hailing Operations Platform</title>
        <meta name="description" content="Onboard drivers, register vehicles, dispatch rides, and track every trip from pickup to drop-off — the operator platform for e-hailing businesses of any size.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
            }
            .float-animation {
                animation: float 6s ease-in-out infinite;
            }
            @keyframes slideInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .slide-in-up {
                animation: slideInUp 0.8s ease-out forwards;
            }
            @keyframes gradient {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }
            .gradient-animate {
                background-size: 200% 200%;
                animation: gradient 15s ease infinite;
            }
        </style>
    </head>
    <body class="bg-gray-900 text-gray-100 antialiased">

        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" x-data="{ scrolled: false, mobileMenuOpen: false }"
                @scroll.window="scrolled = window.pageYOffset > 50"
                :class="scrolled ? 'bg-gray-900/95 backdrop-blur-xl shadow-lg border-b border-gray-800' : 'bg-transparent'">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <a href="/" class="flex items-center gap-3 group">
                        <div class="relative">
                            <img src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" class="h-14 w-auto transform group-hover:scale-105 transition-transform duration-300">
                            <div class="absolute -top-1 -right-1 w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                        </div>
                        <p class="hidden sm:block text-xs text-amber-400 font-medium border-l border-gray-700 pl-3">Ride-Hailing Operations</p>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">Features</a>
                        <a href="#capabilities" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">Capabilities</a>
                        <a href="{{ url('/dashboard') }}" class="text-gray-300 hover:text-amber-400 transition-colors font-medium">Dashboard</a>
                    </div>

                    <!-- Auth Links -->
                    @if (Route::has('login'))
                        <div class="flex items-center gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="hidden sm:flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-amber-500/30 transform hover:scale-105">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                    <span>Dashboard</span>
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="hidden sm:block px-4 py-2 text-gray-300 hover:text-white transition-colors font-medium">
                                    Sign In
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-semibold rounded-xl transition-all duration-300 shadow-lg shadow-amber-500/30 transform hover:scale-105">
                                        <span>Get Started</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                        </svg>
                                    </a>
                                @endif
                            @endauth

                            <!-- Mobile menu button -->
                            <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-gray-400 hover:text-white">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                                    <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif
                </div>

                <!-- Mobile Menu -->
                <div x-show="mobileMenuOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     class="md:hidden mt-4 py-4 border-t border-gray-800"
                     style="display: none;">
                    <div class="flex flex-col gap-2">
                        <a href="#features" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">Features</a>
                        <a href="#capabilities" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">Capabilities</a>
                        @guest
                            <a href="{{ route('login') }}" class="px-4 py-2 text-gray-300 hover:text-amber-400 hover:bg-gray-800 rounded-lg transition-colors">Sign In</a>
                        @endguest
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
            <!-- Photographic Background: real night-time city street with car photo by Luke Miller (@bylukemiller), unsplash.com/photos/a-car-driving-down-a-busy-city-street-at-night-kY1LdHhIcRU -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1715645978589-a43840f228df?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/85 to-gray-900/60"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/40 to-transparent"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZGVmcz48cGF0dGVybiBpZD0iZ3JpZCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBwYXR0ZXJuVW5pdHM9InVzZXJTcGFjZU9uVXNlIj48cGF0aCBkPSJNIDQwIDAgTCAwIDAgMCA0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJyZ2JhKDI1NSwyNTUsMjU1LDAuMDMpIiBzdHJva2Utd2lkdGg9IjEiLz48L3BhdHRlcm4+PC9kZWZzPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JpZCkiLz48L3N2Zz4=')] opacity-30"></div>

            <!-- Floating Elements -->
            <div class="absolute top-20 left-10 w-64 h-64 bg-amber-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation"></div>
            <div class="absolute bottom-20 right-10 w-96 h-96 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float-animation" style="animation-delay: 2s;"></div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
                <div class="grid lg:grid-cols-2 gap-12 items-center">
                    <!-- Left Column -->
                    <div class="space-y-8 slide-in-up">
                        <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/20 rounded-full text-amber-400 text-sm font-medium">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                            </span>
                            <span>Driver, Fleet & Ride Operations</span>
                        </div>

                        <h2 class="text-5xl lg:text-7xl font-bold leading-tight">
                            <span class="bg-gradient-to-r from-white via-gray-100 to-gray-300 bg-clip-text text-transparent">Run Your</span><br>
                            <span class="bg-gradient-to-r from-amber-400 via-amber-500 to-amber-600 bg-clip-text text-transparent">E-Hailing Business</span>
                        </h2>

                        <p class="text-xl text-gray-400 leading-relaxed max-w-xl">
                            Onboard drivers, register vehicles, take ride requests, and track every trip from pickup to drop-off — all from one operator dashboard. Built for anyone from a single owner-driver to a multi-vehicle fleet company.
                        </p>

                        <!-- Key Stats -->
                        <div class="grid grid-cols-3 gap-6 py-6">
                            <div class="text-center">
                                <div class="text-3xl font-bold text-white mb-1">6</div>
                                <div class="text-sm text-gray-400">Ride Lifecycle Stages</div>
                            </div>
                            <div class="text-center">
                                <div class="text-3xl font-bold text-white mb-1">4</div>
                                <div class="text-sm text-gray-400">Vehicle Tiers</div>
                            </div>
                            <div class="text-center">
                                <div class="text-3xl font-bold text-white mb-1">Live</div>
                                <div class="text-sm text-gray-400">Operations Dashboard</div>
                            </div>
                        </div>

                        @guest
                            <div class="flex flex-wrap gap-4">
                                <a href="{{ route('register') }}" class="group flex items-center gap-2 px-8 py-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-amber-500/30 transform hover:scale-105">
                                    <span>Get Started</span>
                                    <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                </a>
                                <a href="#features" class="flex items-center gap-2 px-8 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                    <span>See Features</span>
                                </a>
                            </div>
                            <p class="text-sm text-gray-500">For owner-drivers, small fleets, and growing operators alike</p>
                        @endguest
                    </div>

                    <!-- Right Column - Dashboard Preview -->
                    <div class="relative slide-in-up" style="animation-delay: 0.2s;">
                        <!-- Main Dashboard Card -->
                        <div class="relative bg-gradient-to-br from-gray-800 to-gray-900 rounded-2xl border border-gray-700 shadow-2xl overflow-hidden transform hover:scale-105 transition-transform duration-500">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-6">
                                    <h3 class="text-lg font-bold text-white">Operations Dashboard</h3>
                                    <div class="flex items-center gap-2 px-3 py-1.5 bg-green-500/10 border border-green-500/20 rounded-full">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                        </span>
                                        <span class="text-xs text-green-400 font-semibold">Live</span>
                                    </div>
                                </div>

                                <!-- Stats Grid -->
                                <div class="grid grid-cols-2 gap-3 mb-6">
                                    <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/5 rounded-xl p-4 border border-blue-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-blue-400 font-medium">Active Rides</p>
                                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">12</p>
                                        <p class="text-xs text-blue-400">en route or in progress</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-amber-500/10 to-amber-600/5 rounded-xl p-4 border border-amber-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-amber-400 font-medium">Online Drivers</p>
                                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">8</p>
                                        <p class="text-xs text-amber-400">approved & online</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-xl p-4 border border-purple-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-purple-400 font-medium">Completed</p>
                                            <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">143</p>
                                        <p class="text-xs text-gray-400">rides this week</p>
                                    </div>

                                    <div class="bg-gradient-to-br from-red-500/10 to-red-600/5 rounded-xl p-4 border border-red-500/20">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-xs text-red-400 font-medium">Requested</p>
                                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-2xl font-bold text-white mb-1">3</p>
                                        <p class="text-xs text-red-400">awaiting driver</p>
                                    </div>
                                </div>

                                <!-- Mini Chart -->
                                <div class="bg-gray-900/50 rounded-xl p-4 border border-gray-700/50">
                                    <p class="text-xs text-gray-400 font-medium mb-4">Ride Status Breakdown</p>
                                    <div class="flex items-end justify-between h-24 gap-1.5">
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 45%"></div>
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 62%"></div>
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 55%"></div>
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 78%"></div>
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 88%"></div>
                                        <div class="bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-lg w-full transition-all duration-300 hover:from-amber-400 hover:to-amber-300" style="height: 95%"></div>
                                        <div class="bg-gradient-to-t from-amber-400 to-amber-300 rounded-t-lg w-full shadow-lg shadow-amber-500/50 transition-all duration-300 hover:from-amber-300 hover:to-amber-200" style="height: 100%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Floating Badge -->
                        <div class="absolute -top-6 -right-6 bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-green-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="font-bold">Live Ops</span>
                        </div>

                        <!-- SSO Badge -->
                        <div class="absolute -bottom-6 -left-6 bg-gradient-to-r from-purple-500 to-pink-500 text-white px-6 py-3 rounded-2xl shadow-2xl shadow-purple-500/30 flex items-center gap-2 float-animation transform hover:scale-110 transition-transform" style="animation-delay: 1s;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <span class="font-bold">Ecosystem SSO</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Features Section -->
        <section id="features" class="py-24 px-4 sm:px-6 lg:px-8 bg-gray-900/50 relative overflow-hidden">
            <!-- Background Elements -->
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-amber-500/5 to-transparent"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500/10 border border-amber-500/20 rounded-full text-amber-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        <span>Core Features</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Everything You Need to<br>
                        <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">Run Your Fleet</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        Purpose-built tools for onboarding drivers, managing vehicles, and dispatching rides
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <!-- Feature 1 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-blue-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Driver Onboarding</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Capture license and ID details, move drivers through pending, approved, and suspended states, and track their rating and total rides</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 2 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-purple-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 17l4 4 4-4m-4-5v9M3 3h18v6H3V3z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Vehicle Registration</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Register make, model, year, color, and plate number for every vehicle, classify it as economy, standard, premium, or SUV, and track which is active</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 3 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-amber-500 to-amber-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-amber-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Ride Lifecycle Tracking</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Follow every trip from requested through accepted, en route, arrived, and in progress, to completed or cancelled, with pickup and drop-off addresses</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 4 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-green-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Fare & Revenue Tracking</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">See estimated and final fares per ride, distance covered, and total completed-ride revenue rolled up on the operations dashboard</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 5 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-red-500 to-red-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-red-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Ratings</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">One rating and comment per completed ride, submitted by either passenger or driver, feeding directly into a driver's running rating</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Feature 6 -->
                    <div class="group bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-amber-500/50 transition-all duration-300 hover:shadow-2xl hover:shadow-amber-500/10 transform hover:-translate-y-2 fade-in-up stagger-item">
                        <div class="w-14 h-14 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-indigo-500/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-3 group-hover:text-amber-400 transition-colors">Ecosystem SSO</h3>
                        <p class="text-gray-400 leading-relaxed mb-4">Sign in once across the Dot ecosystem — a user already authenticated elsewhere lands here already signed in via Sanctum</p>
                        <div class="flex items-center gap-2 text-sm text-amber-400 font-medium">
                            <span>Learn more</span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Capabilities Section -->
        <section id="capabilities" class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900"></div>

            <div class="relative z-10 max-w-7xl mx-auto">
                <div class="text-center mb-16 fade-in-up">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-blue-500/10 border border-blue-500/20 rounded-full text-blue-400 text-sm font-medium mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span>Platform Capabilities</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                        Built on a<br>
                        <span class="bg-gradient-to-r from-blue-400 to-cyan-400 bg-clip-text text-transparent">Solid Foundation</span>
                    </h2>
                    <p class="text-xl text-gray-400 max-w-3xl mx-auto">
                        The architecture underneath the driver, vehicle, and ride data your business runs on
                    </p>
                </div>

                <div class="grid lg:grid-cols-2 gap-8 mb-8">
                    <!-- Left Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-blue-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Team-Ready Accounts</h3>
                                    <p class="text-gray-400 leading-relaxed">Built on Jetstream's team and membership model — the foundation for representing everything from a single owner-driver to a multi-vehicle fleet operator.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-purple-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Postgres-Backed Data Layer</h3>
                                    <p class="text-gray-400 leading-relaxed">Driver, vehicle, ride, and rating records live in a structured PostgreSQL database, shared across the Dot ecosystem for a consistent operator identity.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-emerald-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Access Control That Holds Up</h3>
                                    <p class="text-gray-400 leading-relaxed">Driver profile access is policy-gated, so ride history and identifying details stay visible only to the people who should see them.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">
                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-orange-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Token-Based API Access</h3>
                                    <p class="text-gray-400 leading-relaxed">Sanctum-backed authentication is ready for driver and passenger client apps to call the platform's API alongside the web dashboard.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-pink-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Realtime-Ready Architecture</h3>
                                    <p class="text-gray-400 leading-relaxed">Reverb is configured and waiting in the wings for live ride-status broadcasts to driver and passenger clients as dispatch comes online.</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-gray-800 to-gray-900 p-8 rounded-2xl border border-gray-700 hover:border-blue-500/50 transition-all duration-300 fade-in-up">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg shadow-cyan-500/30">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white mb-3">Connected to the Dot Ecosystem</h3>
                                    <p class="text-gray-400 leading-relaxed">Registered as a platform in the wider Dot Ecosystem, built toward publishing ride and corridor insights alongside sibling platforms.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>



        <!-- CTA Section -->
        <section class="py-24 px-4 sm:px-6 lg:px-8 relative overflow-hidden">
            <!-- Photographic Background: real driver navigating with GPS system photo by Dan Gold (@danielcgold), unsplash.com/photos/kARZuSYMfrA -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1482029255085-35a4a48b7084?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0 bg-gray-900/90"></div>
            <div class="absolute inset-0 bg-gradient-to-br from-amber-600/20 via-amber-500/10 to-transparent"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZGVmcz48cGF0dGVybiBpZD0iZ3JpZCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBwYXR0ZXJuVW5pdHM9InVzZXJTcGFjZU9uVXNlIj48cGF0aCBkPSJNIDQwIDAgTCAwIDAgMCA0MCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJyZ2JhKDI1NSwyNTUsMjU1LDAuMDMpIiBzdHJva2Utd2lkdGg9IjEiLz48L3BhdHRlcm4+PC9kZWZzPjxyZWN0IHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIGZpbGw9InVybCgjZ3JpZCkiLz48L3N2Zz4=')] opacity-30"></div>

            <div class="relative z-10 max-w-4xl mx-auto text-center fade-in-up">
                <h2 class="text-4xl lg:text-5xl font-bold text-white mb-6">
                    Ready to Launch Your<br>
                    <span class="bg-gradient-to-r from-amber-400 to-amber-600 bg-clip-text text-transparent">Ride-Hailing Business?</span>
                </h2>
                <p class="text-xl text-gray-400 mb-10 max-w-2xl mx-auto">
                    Onboard your first driver, register a vehicle, and start tracking rides from a single dashboard
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4 mb-8">
                        <a href="{{ route('register') }}" class="group flex items-center gap-2 px-10 py-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-gray-900 font-bold rounded-xl transition-all duration-300 shadow-2xl shadow-amber-500/30 transform hover:scale-105">
                            <span>Get Started</span>
                            <svg class="w-5 h-5 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                        <a href="{{ route('login') }}" class="flex items-center gap-2 px-10 py-4 bg-gray-800 hover:bg-gray-700 text-white font-semibold rounded-xl transition-all duration-300 border border-gray-700 hover:border-gray-600">
                            <span>Sign In</span>
                        </a>
                    </div>
                    <p class="text-sm text-gray-500">Operator-owned data • Ecosystem-connected • Built on Laravel & Livewire</p>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 border-t border-gray-800 bg-gray-900/50">
            <div class="max-w-7xl mx-auto">
                <div class="grid md:grid-cols-4 gap-8 mb-8">
                    <!-- Brand -->
                    <div class="col-span-1">
                        <img src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" class="h-12 w-auto mb-4">
                        <p class="text-gray-400 text-sm">
                            The operator platform for e-hailing businesses — drivers, vehicles, rides, and fares in one place.
                        </p>
                    </div>

                    <!-- Links -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Product</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#features" class="text-gray-400 hover:text-amber-400 transition-colors">Features</a></li>
                            <li><a href="#capabilities" class="text-gray-400 hover:text-amber-400 transition-colors">Capabilities</a></li>
                            <li><a href="{{ url('/dashboard') }}" class="text-gray-400 hover:text-amber-400 transition-colors">Dashboard</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Company</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">About Us</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Contact</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Careers</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Blog</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-white font-semibold mb-4">Legal</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Privacy Policy</a></li>
                            <li><a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">Terms of Service</a></li>
                        </ul>
                    </div>
                </div>

                <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row items-center justify-between gap-4">
                    <p class="text-gray-400 text-sm">
                        &copy; {{ date('Y') }} Ehail. All rights reserved.
                    </p>
                    <div class="flex items-center gap-4">
                        <a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                            </svg>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-amber-400 transition-colors">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </footer>

    </body>
</html>
