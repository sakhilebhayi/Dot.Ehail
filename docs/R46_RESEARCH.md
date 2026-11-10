# R-46 research

Each wave-1 agent appends its own section. Keep both sides on merge conflicts.

## Dispatch and matching (dispatch agent, 2026-11-10)

### Method, stated honestly
Desk research only. I had no access to any operator's code, data or internal documents. Three web searches (2026-11-10) checked the claims that matter most for the design: Uber's own "marketplace/matching" page, Uber's acceptance-rate and deactivation pages, press on Trip Radar (TaxiPoint, News24), and the DiDi research papers (KDD 2019/2021/2022, INFORMS Interfaces). Everything else below comes from general public knowledge (operator help pages, driver forums and trade press read before this session) and is marked **(unverified here)** where I did not re-check it. Where only secondary sources exist (system-design blogs), I say so and do not treat them as fact. I ran no experiments on other platforms.

### What the big platforms do
| Platform | Matching, as publicly described | Source quality |
|---|---|---|
| Uber | Started with "closest driver", then moved to ETA ("closest doesn't always mean quickest": rivers, overpasses, traffic), then to **batched matching**: requests and drivers in an area are collected for a few seconds and paired to cut the *average* wait across everyone, so one rider may get a driver two minutes further away so another waits five minutes instead of ten. Uber notes it may not apply in every market and that it avoids pairing people who rated each other one star. | Uber's own matching page; TaxiPoint 2019 |
| Uber | **Trip Radar**: a request not quickly accepted is shown to several nearby drivers at once; first to tap wins. Declines on Trip Radar do not count against acceptance rate; cancellations do. Drivers' unions (ADCU, London) and South African drivers objected: it pushes drivers to look at the phone while driving and to accept without checking the destination. | Uber pages; TaxiPoint; News24 2023-10-20 |
| Uber | Acceptance rate is a 100-request look-back; Uber says low acceptance does not cause permanent deactivation, but it gates Uber Pro rewards and, in some markets, seeing destinations before accepting. Cancellation rate is treated more seriously. Destination and fare shown before accepting were rolled out in the US. | Uber regional pages; secondary press |
| DiDi | Published a sequence of dispatch methods: combinatorial assignment, then value-function / reinforcement learning (semi-Markov decision process) that weighs the long-term value of putting a driver in a given place and time, deployed with A/B tests and later as the primary dispatch mode in one international market. | KDD 2019 (CVNet), KDD 2021 (V1D3), KDD 2022, INFORMS Interfaces |
| Lyft | Similar ETA-based and batched assignment; historically penalised low acceptance through bonus eligibility; offers a limited number of destination-filter uses per day. **(unverified here)** | general public knowledge |
| Bolt | Sequential offers with a short timer; a "destination" filter with limited daily uses; drivers in South Africa have long complained about low fares and about being "punished" with fewer requests after declines. **(unverified here)** | driver forums, press |
| Grab | Batched assignment with "auto-accept" options and acceptance-rate-linked incentives. **(unverified here)** | general public knowledge |
| inDrive | A different model: the passenger proposes a price, nearby drivers counter-offer or accept, the passenger chooses. No central dispatcher decides who gets the ride. **(unverified here; my search did not return inDrive sources)** | general public knowledge |

A system-design blog describes batched matching as an assignment problem (Hungarian algorithm or linear programming over the pending batch). That is the standard technique, but no platform has confirmed it as its exact implementation, so I treat it as illustration only.

### Recurring driver complaints
1. Acceptance-rate pressure: drivers feel forced to take unprofitable or unsafe trips (long pickups, unfamiliar areas at night) to protect bonuses or status.
2. Opaque punishment: drivers believe declines quietly reduce their future requests, with no limit and no explanation.
3. Blind acceptance: being asked to accept without seeing the destination or the earnings. In South Africa this is a safety issue, not just a money one.
4. Fastest-finger broadcast (Trip Radar style) rewards whoever is staring at the phone, including while driving.
5. Unfair distribution: the same drivers seem to get the good trips while others idle.

### What is fair and measurable here (and what this build does)
Dot.Ehail launches small (one market, few drivers), on a shared host with no daemon (D-1, D-2). A batched global optimiser needs volume to beat sequential offers and a process that runs every few seconds; neither exists here yet. So:

- **Sequential offers, one driver at a time, 15 s to answer**, ranked by pickup ETA (a road router when the geo domain provides one within a time budget; otherwise a bounded straight-line estimate that is labelled as such and used for ranking only, never for fares).
- **Fairness terms are explicit, capped and logged**: idle time since the last trip (credit capped at 30 minutes), a trip-balance term that evens out the day around the candidates' median, and a decline cooldown that is a small fixed penalty for five minutes after three misses in ten minutes. A decline or a timeout never removes a driver from dispatch and never lowers anything beyond that cooldown. Every offer stores every term and weight (`ride_offers.score_terms`), so the effect of any weight change can be measured from the data instead of argued about.
- **Before accepting, the driver sees** pickup distance and ETA (with the method), trip distance and duration, the destination address, the fare, the payment method, the passenger's first name and rating band, and the earnings estimate when pricing can produce one (null, never a guess, until then).
- **The open board** (the old claim queue) is kept as a fallback only after a ride has searched for 45 s, configurable and switchable off. It is listed without push or sound, so nobody is invited to tap while driving. The fairness cost is documented in docs/R46_DISPATCH.md.
- **Measurable**: offers sent, seen, accepted, declined (with reason) and expired per driver and per ride; search time to assignment; reassignments; the score terms of every offer. The ops timeline API exposes all of it.

Deliberately not built: acceptance-rate penalties beyond the capped cooldown, hidden throttling, global batching (revisit when demand per zone justifies it; the `CandidateScorer` is the place it would plug in), and reinforcement-learning repositioning.

### Sources
- [Uber: how matching works](https://www.uber.com/us/en/marketplace/matching/)
- [TaxiPoint: batch matching is why riders do not get the nearest vehicle (2019)](https://www.taxi-point.co.uk/post/2019/06/26/batchmatching-revealed-as-the-reason-uber-users-dont-get-paired-with-the-nearest-vehicle)
- [Uber: understanding acceptance and cancellation rates](https://www.uber.com/en-MX/blog/understanding-acceptance-and-cancellation-rates/)
- [Uber: driver deactivation policy (South Africa)](https://www.uber.com/za/en/blog/driver-deactivation-policy/)
- [TaxiPoint: ADCU pickets Uber over Trip Radar](https://www.taxi-point.co.uk/post/adcu-pickets-uber-s-london-hq-in-protest-against-trip-radar-feature)
- [News24: Uber drivers bemoan distracting Trip Radar (2023-10-20)](https://m.news24.com/news24/tech-and-trends/news/taking-trips-blindly-uber-drivers-bemoan-distracting-new-trip-radar-feature-20231020)
- [DiDi: deep value-network dispatch (KDD 2019)](https://mktal.github.io/pdfs/VNetDispatchKDD.pdf)
- [DiDi: V1D3 value-function dispatch and repositioning (KDD 2021)](https://arxiv.org/abs/2105.08791v3)
- [Reinforcement learning in the wild: RL dispatch deployed (KDD 2022)](https://www.aminer.org/pub/6205d3ef5aee126c0ff1d168/reinforcement-learning-in-the-wild-scalable-rl-dispatching-algorithm-deployed-in-ridehailing)
- [INFORMS Interfaces: ride-hailing order dispatching at DiDi via RL](https://pubsonline.informs.org/doi/fpi/10.1287/inte.2020.1047)
