# Product

<!-- impeccable:product-schema 1 -->

Inferred from the owner's R-46 brief (2026-11-10), `docs/R46_ARCHITECTURE.md` and `docs/R46_AUDIT.md`. The owner's standing order for R-46 is "no questions", so no interview round ran; every line below comes from those documents and is marked (inferred) where it is a reading rather than a quotation.

## Platform

web

## Users
- Passengers in South African cities first (Johannesburg, Pretoria, Cape Town, Durban), booking on a phone, often a low-end Android on prepaid data, often outside at a kerb, sometimes at night. Job: get a safe ride at a fare they agreed to before they got in.
- Drivers, on a phone mounted in the car, for hours at a time, day and night. Job: take offers they can judge (pickup distance, trip estimate, earnings) and get paid what the statement says.
- Operations staff on desktop: live rides, verification, safety incidents, support, pricing, finance.
- Business account admins (company travel) and fleet owners (several cars and drivers) on desktop and phone (inferred).

## Product Purpose
A ride-hailing platform that a passenger and a driver can both trust: the fare is shown with its full breakdown before the request, the driver sees the trip and their earnings before accepting, and safety tools (trip PIN, trip sharing, SOS that states exactly what it did) are part of every ride. Tagline from the brief: "Your Journey. Your Safety. Your Choice."

## Positioning
Fare transparency as a mechanism, not a promise: a versioned, server-side formula (base + distance + time + fees, capped and shown multiplier) the passenger can read line by line before requesting, and a fare that never changes without a stated reason code. Drivers get the same transparency about commission and deductions.

## Operating Context
Mobile web first (PWA), shared cPanel hosting, short polling instead of sockets, maps rendered by the geo agent inside a frame the design system provides, navigation handed to the phone's own maps app, South African emergency numbers (10111, 10177, 112) in the safety flow.

## Capabilities and Constraints
- Booking, dispatch, pricing, safety, payments are being built by other R-46 agents; the shells must show honest empty states until those land.
- Currency: ZAR first, integer minor units. Data costs matter: small JS, self-hosted fonts, no third-party runtime hosts.
- Registration is closed by default (accounts come from InfoDot SSO); `ALLOW_REGISTRATION` opens it.

## Brand Commitments
- Name: Dot.Ehail (part of the Dot ecosystem). Logo: a sunflower-yellow disc carrying a white car, followed by a green chevron; wordmark "dot.ehail" (`public/images/logo.png`). The yellow and green are kept.
- Owner reject list for every Dot surface: warm cream + serif + terracotta; near-black + one acid accent; broadsheet hairlines + zero radius; Inter/Geist/Space Grotesk/Plus Jakarta Sans for chrome; four equal metric tiles; floating rounded cards; side-stripe borders; gradient text; radial blooms. Fonts owned by other Dot platforms are off limits (IBM Plex, Schibsted Grotesk, Martian Mono, Hanken Grotesk, Bricolage Grotesque, Atkinson Hyperlegible Next).
- No em or en dashes in copy.

## Evidence on Hand
- Real: the logo files, the product mechanism (formula in the architecture contract), SA emergency numbers.
- Absent and never to be invented: rider or driver counts, cities live, ratings, testimonials, partner logos, app-store badges, screenshots of screens that do not exist yet.

## Product Principles
1. Show the number before the ask: fares, earnings, waiting time and fees are visible before any commitment.
2. Safety is in the frame of every ride screen, never buried in a menu.
3. Honest states: a feature that is not configured says so.
4. Light on the phone: small pages, few requests, readable in sunlight and at night.

## Accessibility & Inclusion
WCAG 2.2 AA in both themes; 44 px touch targets (56 px for driver primary actions); reduced motion respected; works one-handed on a 360 px wide phone.
