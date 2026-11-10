@php
    $signInUrl = Route::has('login') ? route('login') : url('/login');
    $driveUrl = Route::has('drive.apply') ? route('drive.apply') : $signInUrl;
    $fareStops = [
        ['base', '', 'Base fare', 'A fixed amount for starting the trip, set for each city and ride type.'],
        ['distance', '+', 'Distance', 'A rate per kilometre on the road route to your drop-off. Never a straight line on a map.'],
        ['time', '+', 'Time', 'A rate per minute for the time that route is expected to take.'],
        ['booking', '+', 'Booking fee', 'One flat fee per trip, shown on its own line.'],
        ['fees', '+', 'Fees and tolls', 'Airport or special-area fees and toll roads on your route, each one named.'],
        ['busy', '×', 'Busy times', 'When demand is high a multiplier can apply. It has a cap, and you see it before you request.'],
        ['promo', '−', 'Promotions', 'Any discount comes off here, with the promotion named.'],
        ['total', '=', 'Your fare', 'The price you agree to. It changes only for a reason we show you, such as a stop you added or waiting time at pickup.'],
    ];
    $driverFacts = [
        ['offer', 'The offer', 'Every offer shows the pickup distance, the trip and what you earn, before you accept.'],
        ['trip', 'The trip', 'Navigation opens in Google Maps, Waze or Apple Maps. Big buttons, few words, dark after sunset.'],
        ['statement', 'The statement', 'Each trip lists the fare, the commission, any deductions and what is yours.'],
        ['commission', 'The commission', 'One commission rate per market, the same on every trip, shown on each offer and statement. The rate is published before launch.'],
        ['payouts', 'Payouts', 'Payouts run on a set schedule, and every amount matches your statement.'],
    ];
    $questions = [
        ['How is my fare worked out?', 'From the road route to your drop-off: a base fare, distance, time and any named fees, shown line by line before you request.'],
        ['Can the price change after I request?', 'Only for a reason you can see, such as a stop you added, a new drop-off or waiting time at pickup. The reason is printed on your receipt.'],
        ['What happens when I press SOS?', 'Our operations team and the people you trust are alerted with your trip, and South Africa\'s emergency numbers are one tap away. SOS does not call the police or an ambulance for you.'],
        ['How are drivers checked?', 'Drivers upload their licence, ID and vehicle papers. A person reviews them before the first trip and again when they expire.'],
        ['How can I pay?', 'By card through Paystack, or in cash where the driver accepts it. Business trips go on the company account.'],
        ['Where can I ride?', 'Dot.Ehail starts in South Africa. When you set a pickup, the app tells you straight away whether rides are available there.'],
        ['Will it eat my data?', 'It is built to be light. Fonts and icons come from our own server and nothing loads from other sites.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en-ZA" class="eh lp" data-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', [
        'title' => 'Rides with the full fare shown first',
        'description' => 'Dot.Ehail: ride-hailing for South Africa. See the full fare breakdown before you request, safety tools on every trip, and drivers who know what they earn before they accept.',
        'viteEntries' => ['resources/css/app.css', 'resources/js/eh/landing.js'],
    ])
    <link rel="preload" as="image" href="{{ asset('images/landing/bridge-1600.webp') }}" imagesrcset="{{ asset('images/landing/bridge-800.webp') }} 800w, {{ asset('images/landing/bridge-1600.webp') }} 1600w" imagesizes="(min-width: 960px) 50vw, 100vw">
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>


    <main id="main" tabindex="-1">
        <section class="lp-hero" aria-labelledby="hero-title">
            <div class="lp-hero__card">
            <header class="lp-bar">
                    <div class="lp-bar__in">
                        <x-eh.logo href="{{ url('/') }}" size="34" />
                        <nav class="lp-bar__links" aria-label="Sections">
                            <a href="#fares">Fares</a>
                            <a href="#safety">Safety</a>
                            <a href="#drivers">Drivers</a>
                            <a href="#business">Business</a>
                        </nav>
                        @auth
                            <x-eh.button size="sm" :href="route('home')">Open the app</x-eh.button>
                        @else
                            <x-eh.button size="sm" :href="$signInUrl">Sign in</x-eh.button>
                        @endauth
                    </div>
                </header>
                <ol class="lp-route" aria-label="Our promise">
                    <li class="lp-route__stop"><span>Your journey.</span></li>
                    <li class="lp-route__stop"><span>Your safety.</span></li>
                    <li class="lp-route__stop lp-route__stop--end"><span>Your choice.</span></li>
                </ol>
                <h1 id="hero-title">See the fare before you ride</h1>
                <p class="lp-hero__sentence">South African rides, with every part of the fare shown before you request.</p>
                <div class="lp-hero__actions">
                    @auth
                        <x-eh.button size="lg" :href="route('home')" icon-right="arrow-right">Open the app</x-eh.button>
                    @else
                        <x-eh.button size="lg" :href="$signInUrl" icon-right="arrow-right">Sign in to ride</x-eh.button>
                    @endauth
                </div>
                <ul class="lp-hero__links">
                    <li><a href="#fares">How fares work</a></li>
                    <li><a href="#safety">Safety on every ride</a></li>
                    <li><a href="#drivers">Drive with us</a></li>
                </ul>
            </div>
            <figure class="lp-photo lp-hero__photo">
                <img src="{{ asset('images/landing/bridge-1600.webp') }}" srcset="{{ asset('images/landing/bridge-800.webp') }} 800w, {{ asset('images/landing/bridge-1600.webp') }} 1600w" sizes="(min-width: 960px) 50vw, 100vw" width="1600" height="1199" alt="The road across the Nelson Mandela Bridge in Johannesburg, yellow lane lines running toward the city" fetchpriority="high" data-caption="Nelson Mandela Bridge, Johannesburg">
                <figcaption>Nelson Mandela Bridge, Johannesburg</figcaption>
            </figure>
        </section>

        <section class="lp-section" id="fares" aria-labelledby="fares-title">
            <div class="lp-wrap">
                <div class="lp-fares__head" data-lp-reveal>
                    <h2 class="lp-h2" id="fares-title">How your fare is built</h2>
                    <p class="lp-lede">Before you request, every part of the price is on your screen. Tap a part of the fare to see what it pays for.</p>
                </div>
                <div class="lp-fareline" data-lp-fareline>
                    <ol class="lp-fareline__stops" aria-label="How a fare is built" data-lp-reveal>
                        @foreach ($fareStops as [$key, $op, $label, $text])
                            <li class="lp-stop {{ $key === 'total' ? 'lp-stop--total' : '' }}">
                                <button type="button" class="lp-stop__btn" data-lp-stop aria-expanded="true" aria-controls="fare-{{ $key }}">
                                    <span class="lp-stop__dot" aria-hidden="true"></span>
                                    @if ($op)<span class="lp-stop__op" aria-hidden="true">{{ $op }}</span>@endif
                                    <span>{{ $label }}</span>
                                </button>
                                <div class="lp-stop__panel" id="fare-{{ $key }}" role="region" aria-label="{{ $label }}"><p>{{ $text }}</p></div>
                            </li>
                        @endforeach
                    </ol>
                    <div class="lp-fareline__panels" data-lp-panels aria-live="polite"></div>
                </div>
                <p class="lp-fareline__note">A fare never changes without a reason on your receipt.</p>
            </div>
        </section>

        <section class="lp-section" id="safety" aria-labelledby="safety-title">
            <div class="lp-wrap lp-safety">
                <figure class="lp-photo lp-safety__photo">
                    <img src="{{ asset('images/landing/seapoint-1200.webp') }}" srcset="{{ asset('images/landing/seapoint-640.webp') }} 640w, {{ asset('images/landing/seapoint-1200.webp') }} 1200w" sizes="(min-width: 960px) 40vw, 100vw" width="1200" height="900" loading="lazy" decoding="async" alt="Lights of Sea Point, Cape Town, at night">
                    <figcaption>Sea Point, Cape Town, at night</figcaption>
                </figure>
                <div>
                    <div data-lp-reveal>
                        <h2 class="lp-h2" id="safety-title">Safety on every ride</h2>
                    </div>
                    <x-eh.stepper label="A ride, stop by stop" :stateful="false" :steps="[
                        ['label' => 'Before you get in', 'meta' => 'Check the driver\'s name, photo, car and number plate. A person checks every driver\'s documents before their first trip.'],
                        ['label' => 'At the kerb', 'meta' => 'Your driver can start the trip only with the four-digit PIN you give them.'],
                        ['label' => 'On the way', 'meta' => 'Share your live trip with someone you trust. SOS alerts our operations team and your trusted contacts.'],
                        ['label' => 'After you arrive', 'meta' => 'Rate the trip, and report anything that felt wrong, privately.'],
                    ]" />
                    <x-eh.banner tone="safety" title="In an emergency, call directly" />
                    <p class="lp-safety__honest">SOS does not dispatch police or an ambulance. It tells our team and your contacts where you are and puts these numbers one tap away. Calls work without data.</p>
                </div>
            </div>
        </section>

        <section class="lp-section" id="drivers" aria-labelledby="drivers-title">
            <div class="lp-wrap lp-drive">
                <div>
                    <div data-lp-reveal>
                        <h2 class="lp-h2" id="drivers-title">Know what you earn before you accept</h2>
                        <p class="lp-lede" style="margin-top: var(--eh-space-5)">One commission rate per market, the same on every trip and printed on every statement. The rate is published before launch.</p>
                    </div>
                    <ul class="lp-index" role="tablist" aria-label="For drivers" data-lp-index data-lp-reveal>
                        @foreach ($driverFacts as $i => [$key, $label, $text])
                            <li role="presentation"><button type="button" role="tab" id="drive-tab-{{ $key }}" aria-controls="drive-fact-{{ $key }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" tabindex="{{ $i === 0 ? '0' : '-1' }}">{{ $label }}</button></li>
                        @endforeach
                    </ul>
                    @foreach ($driverFacts as $i => [$key, $label, $text])
                        <p class="lp-drive__fact" role="tabpanel" id="drive-fact-{{ $key }}" aria-labelledby="drive-tab-{{ $key }}">{{ $text }}</p>
                    @endforeach
                    <x-eh.button size="lg" variant="secondary" :href="$driveUrl" icon="steering-wheel">Drive with Dot.Ehail</x-eh.button>
                </div>
                {{-- What a driver reads on every offer before accepting, drawn as the route line (no photograph: the
                     jury read the minibus taxi as the wrong kind of service; no figures: rates are not published yet). --}}
                <figure class="lp-drive__disc lp-offer" aria-labelledby="lp-offer-cap">
                    <ol class="lp-offer__stops">
                        <li><span class="lp-offer__dot" aria-hidden="true"></span><b>Pickup</b><span>how far, and how many minutes</span></li>
                        <li><span class="lp-offer__dot" aria-hidden="true"></span><b>Trip</b><span>distance, time, drop-off area</span></li>
                        <li><span class="lp-offer__dot" aria-hidden="true"></span><b>Fare</b><span>what the rider pays, line by line</span></li>
                        <li><span class="lp-offer__dot" aria-hidden="true"></span><b>Commission</b><span>one rate, the same every trip</span></li>
                        <li class="lp-offer__total"><span class="lp-offer__dot" aria-hidden="true"></span><b>You earn</b><span>the amount on your statement</span></li>
                    </ol>
                    <figcaption id="lp-offer-cap">On every offer, before you accept</figcaption>
                </figure>
            </div>
        </section>

        <div class="lp-band" role="presentation">
            <figure class="lp-photo">
                <img src="{{ asset('images/landing/n1-signs-2400.webp') }}" srcset="{{ asset('images/landing/n1-signs-1200.webp') }} 1200w, {{ asset('images/landing/n1-signs-2400.webp') }} 2400w" sizes="100vw" width="2400" height="343" loading="lazy" decoding="async" alt="Overhead road signs on the N1 towards Johannesburg, with the Midrand exit and the R561 to Allandale Road">
                <figcaption>N1 towards Johannesburg, Gauteng</figcaption>
            </figure>
        </div>

        <section class="lp-section" id="business" aria-labelledby="business-title">
            <div class="lp-wrap lp-accounts">
                <div>
                    <div data-lp-reveal>
                        <h2 class="lp-h2" id="business-title">For companies and fleet owners</h2>
                    </div>
                    <div class="lp-accounts__cols" data-lp-reveal>
                        <div class="lp-accounts__col">
                            <h3>Business accounts</h3>
                            <p>Your team rides on the company account, inside the rules you set, with one invoice a month.</p>
                        </div>
                        <div class="lp-accounts__col">
                            <h3>Fleet owners</h3>
                            <p>Add your cars and drivers, see documents before they expire, and follow each car's earnings.</p>
                        </div>
                    </div>
                </div>
                <figure class="lp-photo lp-accounts__photo">
                    <img src="{{ asset('images/landing/gandhi-1024.webp') }}" srcset="{{ asset('images/landing/gandhi-640.webp') }} 640w, {{ asset('images/landing/gandhi-1024.webp') }} 1024w" sizes="(min-width: 960px) 40vw, 100vw" width="1024" height="681" loading="lazy" decoding="async" alt="Buses and office towers around Gandhi Square in central Johannesburg">
                    <figcaption>Gandhi Square, Johannesburg</figcaption>
                </figure>
            </div>
        </section>

        <section class="lp-section" id="questions" aria-labelledby="faq-title">
            <div class="lp-wrap lp-faq">
                <div class="lp-faq__head" data-lp-reveal>
                    <h2 class="lp-h2" id="faq-title">Questions</h2>
                </div>
                <div class="lp-faq__list" data-lp-reveal>
                    @foreach ($questions as $i => [$question, $answer])
                        <details class="lp-q" name="faq" @if($i === 0) open @endif>
                            <summary>{{ $question }}<span class="lp-q__sign" aria-hidden="true"></span></summary>
                            <div class="lp-q__a"><p>{{ $answer }}</p></div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="lp-section" aria-labelledby="close-title">
            <div class="lp-wrap lp-close" data-lp-reveal>
                <h2 class="lp-h2" id="close-title">Ready when you are</h2>
                <div class="lp-hero__actions">
                    @auth
                        <x-eh.button size="lg" :href="route('home')" icon-right="arrow-right">Open the app</x-eh.button>
                    @else
                        <x-eh.button size="lg" :href="$signInUrl" icon-right="arrow-right">Sign in to ride</x-eh.button>
                    @endauth
                    <x-eh.button size="lg" variant="secondary" :href="$driveUrl" icon="steering-wheel">Drive with Dot.Ehail</x-eh.button>
                </div>
            </div>
        </section>
    </main>

    <footer class="lp-foot">
        <div class="lp-wrap">
            <div class="lp-foot__mark" aria-hidden="true">
                <span class="lp-foot__disc"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="color: var(--eh-brand-white)">{!! \App\Support\Ui\Icons::body('car-sedan') !!}</svg></span>
                <svg class="lp-foot__chev" viewBox="0 0 62 100"><path d="M14 12l34 38-34 38" fill="none" stroke="currentColor" stroke-width="22" stroke-linecap="round" stroke-linejoin="round" style="color: var(--eh-brand-green)"/></svg>
                <span class="lp-foot__word">Dot<span>.</span>Ehail</span>
            </div>
            <div class="lp-foot__grid">
                <nav aria-label="Ride">
                    <ul>
                        <li><a href="{{ $signInUrl }}">Sign in</a></li>
                        <li><a href="#fares">How fares work</a></li>
                        <li><a href="#safety">Safety</a></li>
                    </ul>
                </nav>
                <nav aria-label="Drive">
                    <ul>
                        <li><a href="{{ $driveUrl }}">Drive with Dot.Ehail</a></li>
                        <li><a href="#drivers">What drivers see</a></li>
                        <li><a href="#business">Fleets and business</a></li>
                    </ul>
                </nav>
                <nav aria-label="Legal">
                    <ul>
                        @if (Route::has('terms.show'))<li><a href="{{ route('terms.show') }}">Terms</a></li>@endif
                        @if (Route::has('policy.show'))<li><a href="{{ route('policy.show') }}">Privacy</a></li>@endif
                        <li><a href="{{ route('cookies') }}">Cookies</a></li>
                    </ul>
                </nav>
                <div>
                    <p style="margin:0">Emergency numbers work without data: <a href="tel:10111">10111</a> police, <a href="tel:10177">10177</a> ambulance, <a href="tel:112">112</a> from a mobile.</p>
                </div>
            </div>
            <div class="lp-foot__fine">
                <p>Dot.Ehail is part of the Dot ecosystem.</p>
                <p>Pictures: <a href="https://commons.wikimedia.org/w/index.php?curid=76253417">"Johannesburg, Nelson Mandela Bridge"</a> by S'busiso siso, <a href="https://creativecommons.org/licenses/by-sa/4.0/">CC BY-SA 4.0</a>; <a href="https://commons.wikimedia.org/wiki/File:Cape_Town_(ZA),_Sea_Point,_Nachtansicht_--_2024_--_1867-70_-_2.jpg">"Cape Town (ZA), Sea Point, Nachtansicht"</a> by Dietmar Rabich, <a href="https://creativecommons.org/licenses/by-sa/4.0">CC BY-SA 4.0</a>; <a href="https://commons.wikimedia.org/w/index.php?curid=70630291">"Road signs N1 Johannesburg"</a> by Marco Scholtz, <a href="https://creativecommons.org/publicdomain/zero/1.0/deed.en">CC0</a>; <a href="https://www.flickr.com/photos/25779097@N08/8722268247">"View of Joburg inner city from Gandhi Square"</a> by South African Tourism, <a href="https://creativecommons.org/licenses/by/2.0/">CC BY 2.0</a>. Cropped and resized. Fonts: Barlow (SIL Open Font License). Icons: Lucide (ISC) and our own.</p>
            </div>
        </div>
    </footer>
</body>
</html>
