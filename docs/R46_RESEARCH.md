# R-46 research

Part 1 (UX and product study) is by the ds agent. Other agents append their sections below it: pricing, dispatch, safety, payments.

## Part 1: UX and product study (ds, 2026-11-10)

### Method, stated honestly

This is a desk study from public product knowledge: how the apps work as publicly documented and widely reported up to mid-2026, their help centres and published policies, recurring themes in app-store reviews and driver communities (Reddit, Facebook driver groups, South African news coverage of e-hailing strikes and regulation), and industry reporting. I had no device access, did not install or run any of the apps, did not scrape stores, and did not interview users. Nothing here is a measured statistic; where a claim is a recurring complaint pattern rather than a documented fact, it says so. Before any of this informs marketing copy, a human should check it against current app versions.

Products considered: Uber, Bolt, inDrive, DiDi (DiDi Rider operated in South Africa until its 2023 exit), Lyft, Grab, and regional players: Yango (active in several African markets), Little (Kenya), SafeBoda (Uganda, Nigeria history), and South African metered-taxi apps and dispatchers (the "metered taxi" segment that e-hailing displaced in Johannesburg, Pretoria, Cape Town and Durban).

### What the leaders do well (and Dot.Ehail must match)

| Pattern | Who does it best | What it means for Dot.Ehail |
|---|---|---|
| Map-first booking: pickup pin, destination search, category comparison in a bottom sheet | Uber, Bolt, Grab | The passenger shell is built around a map frame and a bottom sheet with snap points (`<x-eh.map-frame>`, `<x-eh.sheet>`). |
| Upfront price before request | Uber (upfront pricing), Bolt, DiDi | Table stakes. Dot.Ehail goes further: the full breakdown, not just the total. |
| Driver details before pickup: name, photo, car, plate, rating | everyone | Shown in the trip card; the plate gets the largest type because that is what a passenger checks at the kerb. |
| Ride PIN at pickup | Uber (optional PIN), Bolt in some markets, Grab | Architecture makes the 4-digit PIN the default; the stepper and safety copy carry it. |
| Share trip status | Uber, Bolt, Lyft | Trip share link (safety agent). |
| In-app emergency button | Uber (safety toolkit), Bolt (SOS), Grab | SOS with an honest statement of what it does (architecture section 7). |
| Lightweight apps for low-end phones | Uber Lite (retired 2022), Bolt Lite, Grab's low-data modes | The PWA is the "lite" app: small JS (shell 2.4 KB gzip), self-hosted fonts, no third-party runtime hosts, shell-only caching. |
| Cash payments | Bolt, inDrive, Uber in SA | Cash is a first-class payment method (money agent). |
| Driver destination filter, heat maps, earnings view | Uber, Bolt | Driver shell has Earnings as a first-level destination. |

### Recurring complaints (patterns, not measured rates)

**Passengers**
1. **Surprise fares.** Price on arrival differs from the quote (route changes, waiting, tolls, surge applied after a delay). Complaints concentrate on not knowing *why* the fare changed. Dot.Ehail answer: a fare never changes without a reason code shown on the receipt (architecture section 4); the landing page makes this its first promise.
2. **Surge opacity.** Multipliers shown as a number without a cap or explanation, or folded into the total. Answer: the busy-time multiplier is a named, capped line in the breakdown.
3. **Cancellations and long waits.** Drivers accept then cancel (often after seeing the destination or because the pickup is far for the pay), or the "searching" state runs on. Answer: drivers see trip and earnings before accepting (less reason to cancel after), searching has a visible window and an honest "no driver found" end state (`expired`).
4. **Safety at night and in unfamiliar areas.** Fake or swapped drivers, wrong car, harassment, and in South Africa specifically, crime risk at pickup points and fear of hijacking or robbery. Answer: plate and photo first, PIN to start, live share, SOS that alerts operations and trusted contacts, emergency numbers one tap away, and copy that never claims an armed response the product does not have.
5. **Support dead ends.** Chatbots and canned replies, no human for serious incidents, refunds hard to get. Answer: support cases with a human queue (safety agent), and the ops shell has Support as a first-level destination.
6. **Data and battery cost.** On prepaid data, large apps and map tiles hurt; low-storage phones uninstall big apps. Answer: PWA, no app-store install needed, tiny shell, map tiles only when the map is on screen (geo agent).
7. **Pickup confusion.** Pins land on the wrong side of a road or inside an estate; gated complexes and malls have specific pickup points. Answer: map-frame and sheet patterns support a "confirm pickup" step; zones (geo) can carry named pickup points.

**Drivers**
1. **Pay opacity and commission.** Drivers report not knowing the commission per trip, or that the commission percentage effectively rose. South African driver protests (2021 to 2024) repeatedly cited commission rates and per-km rates that do not cover fuel and vehicle costs. Answer: earnings statement lines (fare, commission, deductions, net) and the offer card shows earnings before accepting.
2. **Destination hidden until pickup.** Leads to cancellations and unsafe areas. Answer: the trip is shown on the offer.
3. **Deactivation without explanation.** Accounts blocked by automated systems with no appeal. Answer: verification reviews with reasons and resubmission (safety agent); ops shell has Verification and Drivers destinations.
4. **Safety of drivers.** Robberies of drivers by passengers posing as riders, cash trips in particular. Answer: the safety banner and SOS exist in the driver shell too; passenger verification and ratings are two-sided.
5. **Fleet owner and renter disputes.** In South Africa many drivers rent cars from fleet owners ("partners"); disputes over targets and payouts are common. Answer: a fleet shell with drivers, vehicles, documents and earnings per car.
6. **App distraction while driving.** Small targets, lots of text, notifications. Answer: the driver shell uses 56px primary actions, 18px body, a single state bar, and hands navigation to the phone's maps app.
7. **Regulation.** Operating licences under the National Land Transport Act amendments (2023/2024) and municipal enforcement (impounds). Answer: documents and expiry tracking; the design system has a licence-disc icon and a document status vocabulary ready for it.

### Regional notes

- **inDrive** lets passengers propose a fare and drivers counter. It wins on price-sensitivity and loses on trust and speed. Dot.Ehail keeps server-authoritative fares (architecture section 4) but shares inDrive's lesson that people want agency over price: show the parts and let them choose the category.
- **Yango** emphasises in-app safety features and fixed pricing in several African cities.
- **Little (Kenya)** built corporate accounts and M-Pesa integration early: business accounts and local payment rails matter in African markets.
- **SafeBoda** built community trust through branded, trained riders and a visible code of conduct: trust is designed, not assumed.
- **Metered taxis in SA** lost to e-hailing on price visibility and convenience, not on safety; the meter was opaque. Dot.Ehail's breakdown is the opposite of the meter.

### Where Dot.Ehail can be better (and what the shells already carry)

1. **The fare as a readable formula, before and after the trip.** Landing signature (the fare line), money component (minor units, tabular figures), status vocabulary with reason codes.
2. **Honest states everywhere.** Empty states that say what is not built yet; SOS copy that says what it does not do; navigation that hides destinations whose routes do not exist (`App\Support\Ui\Navigation`).
3. **Safety in the frame.** A safety control in every passenger and driver top bar; emergency numbers as one-tap calls that work without data.
4. **Light on the phone.** No third-party hosts, self-hosted fonts, 2.4 KB shell script, shell-only service worker, dark night mode for drivers.
5. **Driver respect.** Earnings before accept, statement lines, large targets, night theme by default after 18:00.
6. **Local identity.** Sign green and road-marking yellow from South African roads, ZAR formatting ("R1 250.50"), South African emergency numbers, photographs of Johannesburg and Cape Town streets.

### Open questions for the owner (logged, not blocking)

- Languages: English only for now; isiZulu, Afrikaans, isiXhosa and Sesotho copy would widen reach (i18n keys are not yet extracted).
- Whether to offer a passenger-proposed fare mode (inDrive style); the architecture currently says no.
- Armed-response or medical partner integration for SOS (architecture section 7: an owner decision).
