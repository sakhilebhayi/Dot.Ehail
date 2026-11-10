# R-46 decisions log

Append-only. `D-n (agent, date): decision. Why.` Keep both sides on merge conflicts.

D-1 (coordinator, 2026-11-10): Real-time is short polling with versioned deltas; broadcasting events stay for hosts that can run Reverb. Why: the shared cPanel host cannot run a WebSocket server (live /up/realtime is "degraded").
D-2 (coordinator, 2026-11-10): Dispatch advances on demand (every poll and the per-minute scheduler call Dispatcher::tick). Why: no daemons on the host; offers must still expire within seconds.
D-3 (coordinator, 2026-11-10): Money is integer minor units with ISO currency, versioned pricing rule sets, server-authoritative quotes with expiry. Why: brief section 6 pricing integrity.
D-4 (coordinator, 2026-11-10): Route-based fares require a real road route from a configured routing provider; straight-line distance is never billed. Why: brief section 10.
D-5 (coordinator, 2026-11-10): Turn-by-turn navigation hands off to the device's maps app; SOS creates an incident, notifies contacts and ops, offers SA emergency numbers; no claim of emergency dispatch. Why: honesty rules, no provider contracts.
