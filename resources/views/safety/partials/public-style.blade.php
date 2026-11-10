<style>
/* hallmark: app-screen scope, direction: Dot control room (memory dot-design-language), theme: none (custom, ecosystem system), signature: the registration readout, deal: manual */
/* critique: utility page read under stress on a cheap phone; words before figures, one amber signal, panels share edges, no webfont download (privacy, no CDN) */
:root {
    --eh-ground: #0f1113;
    --eh-rule: #2a2f35;
    --eh-panel: #16191c;
    --eh-panel-sunken: #121417;
    --eh-ink: #eceae4;
    --eh-ink-2: #b9b6ad;
    --eh-ink-3: #8d8a82;
    --eh-signal: #f2a70b;
    --eh-signal-ink: #1a1204;
    --eh-stop: #ff8a7a;
    --eh-focus: #f2a70b;
    --eh-font-ui: "IBM Plex Sans", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --eh-font-display: "IBM Plex Sans Condensed", "Arial Narrow", "Roboto Condensed", var(--eh-font-ui);
    --eh-font-mono: "IBM Plex Mono", ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    --eh-radius: 2px;
    --eh-ease: cubic-bezier(.2, .7, .2, 1);
}
@media (prefers-color-scheme: light) {
    :root {
        --eh-ground: #f4f2ed;
        --eh-rule: #cfcbc1;
        --eh-panel: #fbfaf7;
        --eh-panel-sunken: #efece5;
        --eh-ink: #1b1d20;
        --eh-ink-2: #45484d;
        --eh-ink-3: #5f6268;
        --eh-signal: #8a5c07;
        --eh-signal-ink: #ffffff;
        --eh-stop: #a32a1c;
        --eh-focus: #8a5c07;
    }
}
html, body { overflow-x: clip; }
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--eh-ground);
    color: var(--eh-ink);
    font-family: var(--eh-font-ui);
    font-size: 1rem;
    line-height: 1.5;
    -webkit-text-size-adjust: 100%;
}
.eh-wrap { max-width: 40rem; margin: 0 auto; padding: 1rem; }
.eh-mark { font-family: var(--eh-font-display); font-weight: 600; font-size: 1rem; color: var(--eh-ink-2); margin: .25rem 0 1rem; }
.eh-board { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1px; background: var(--eh-rule); border: 1px solid var(--eh-rule); border-radius: var(--eh-radius); }
.eh-board > * { background: var(--eh-panel); padding: 1rem; min-width: 0; }
.eh-state { background: var(--eh-panel-sunken); }
.eh-state h1 { font-family: var(--eh-font-display); font-weight: 600; font-size: clamp(1.6rem, 6vw, 2.2rem); line-height: 1.15; margin: 0; overflow-wrap: break-word; min-width: 0; }
.eh-state p { margin: .35rem 0 0; color: var(--eh-ink-2); }
.eh-label { display: block; font-size: .875rem; color: var(--eh-ink-3); margin-bottom: .2rem; }
.eh-value { font-size: 1.125rem; overflow-wrap: break-word; }
.eh-readout { font-family: var(--eh-font-mono); font-variant-numeric: tabular-nums; font-size: clamp(1.75rem, 9vw, 2.6rem); letter-spacing: 0; color: var(--eh-signal); line-height: 1.1; overflow-wrap: anywhere; }
.eh-mono { font-family: var(--eh-font-mono); font-variant-numeric: tabular-nums; }
.eh-pair { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1px; background: var(--eh-rule); padding: 0; }
.eh-pair > div { background: var(--eh-panel); padding: 1rem; min-width: 0; }
.eh-note { color: var(--eh-ink-2); font-size: .9375rem; }
.eh-note p { margin: 0 0 .5rem; }
.eh-calls { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
a, .eh-button { color: var(--eh-signal); }
a:focus-visible, .eh-button:focus-visible, .eh-call:focus-visible { outline: 3px solid var(--eh-focus); outline-offset: 2px; }
.eh-call {
    display: inline-flex; align-items: center; min-height: 48px; padding: .5rem .9rem;
    border: 1px solid var(--eh-rule); border-radius: var(--eh-radius); background: var(--eh-panel-sunken);
    color: var(--eh-ink); text-decoration: none; font-weight: 600;
}
.eh-call:hover { border-color: var(--eh-signal); }
.eh-call:active { transform: translateY(1px); }
.eh-button {
    display: inline-flex; align-items: center; justify-content: center; min-height: 52px; padding: .75rem 1.25rem;
    border: 0; border-radius: var(--eh-radius); background: var(--eh-signal); color: var(--eh-signal-ink);
    font: 600 1rem/1.2 var(--eh-font-ui); cursor: pointer; transition: transform 120ms var(--eh-ease), opacity 120ms var(--eh-ease);
}
.eh-button:hover { opacity: .92; }
.eh-button:active { transform: translateY(1px); }
.eh-button:disabled { opacity: .5; cursor: not-allowed; }
.eh-fine { color: var(--eh-ink-3); font-size: .875rem; margin-top: 1rem; }
.eh-stale { color: var(--eh-stop); }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; }
@media (prefers-reduced-motion: reduce) { .eh-button, .eh-call { transition: none; } .eh-button:active, .eh-call:active { transform: none; } }
</style>
