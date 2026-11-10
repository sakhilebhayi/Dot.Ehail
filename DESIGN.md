---
version: alpha
name: Route Line
description: Dot.Ehail design system. South African ride-hailing drawn as a route line with stops on cool map paper, in sign green and road-marking yellow.
colors:
  primary: "#0b6a3c"
  on-primary: "#ffffff"
  primary-hover: "#08552f"
  primary-tint: "#d9eedf"
  on-primary-tint: "#0a4a2b"
  signal: "#f2c230"
  on-signal: "#0f1f19"
  ground: "#f1f4f0"
  surface: "#ffffff"
  surface-2: "#e4eae4"
  surface-3: "#d6ded7"
  ink: "#0f1f19"
  ink-2: "#3a4a43"
  ink-3: "#56665e"
  rule: "#d3dbd5"
  rule-strong: "#7c8b84"
  go: "#0e6e3d"
  go-bg: "#ddf0e3"
  caution: "#7a4f00"
  caution-bg: "#fdf1c9"
  stop: "#b3261e"
  stop-bg: "#fbe4e1"
  stop-fill: "#b3261e"
  on-stop-fill: "#ffffff"
  info: "#1d4f9c"
  info-bg: "#e1ebf8"
  focus: "#1d5bd6"
  brand-yellow: "#f1c62f"
  brand-green: "#9dcb86"
  map-land: "#eef1ec"
  map-road: "#ffffff"
  map-road-casing: "#cdd5ce"
  map-road-major: "#f7dc80"
  map-water: "#b9d6e6"
  map-park: "#cfe4c8"
  map-building: "#e1e6e0"
  map-label: "#3a4a43"
  map-route: "#0b6a3c"
  map-pickup: "#f2c230"
  map-dropoff: "#0f1f19"
  map-driver: "#1d5bd6"
  night-ground: "#1a2a24"
  night-surface: "#22342d"
  night-surface-2: "#2a3d35"
  night-ink: "#e8f0eb"
  night-ink-2: "#b8c6be"
  night-ink-3: "#a0b1a8"
  night-primary: "#48b17b"
  night-on-primary: "#04120a"
  night-primary-tint: "#24493a"
  night-on-primary-tint: "#b4e8c8"
  night-stop: "#ff9a8b"
  night-focus: "#8fbaff"
  night-map-land: "#182721"
  night-map-road: "#2a3b34"
  night-map-water: "#0f2a36"
  night-map-park: "#15291c"
typography:
  display-xl:
    fontFamily: Barlow Condensed
    fontSize: 6rem
    fontWeight: 700
    lineHeight: 0.92
    letterSpacing: -0.02em
  display-lg:
    fontFamily: Barlow Condensed
    fontSize: 4.75rem
    fontWeight: 700
    lineHeight: 0.95
    letterSpacing: -0.015em
  display-md:
    fontFamily: Barlow Condensed
    fontSize: 3rem
    fontWeight: 700
    lineHeight: 1
    letterSpacing: -0.01em
  state-title:
    fontFamily: Barlow Condensed
    fontSize: 2.25rem
    fontWeight: 700
    lineHeight: 1
  h1:
    fontFamily: Barlow
    fontSize: 1.75rem
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: -0.01em
  h2:
    fontFamily: Barlow
    fontSize: 1.375rem
    fontWeight: 700
    lineHeight: 1.2
  h3:
    fontFamily: Barlow
    fontSize: 1.125rem
    fontWeight: 600
    lineHeight: 1.3
  body-lg:
    fontFamily: Barlow
    fontSize: 1.125rem
    fontWeight: 400
    lineHeight: 1.5
  body-md:
    fontFamily: Barlow
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: Barlow
    fontSize: 0.875rem
    fontWeight: 600
    lineHeight: 1.3
  caption:
    fontFamily: Barlow
    fontSize: 0.8125rem
    fontWeight: 600
    lineHeight: 1.2
  money-lg:
    fontFamily: Barlow Condensed
    fontSize: 2.25rem
    fontWeight: 700
    lineHeight: 1
    fontFeature: '"tnum" 1, "lnum" 1'
rounded:
  sm: 6px
  md: 12px
  lg: 20px
  pill: 999px
spacing:
  "1": 4px
  "2": 8px
  "3": 12px
  "4": 16px
  "5": 20px
  "6": 24px
  "8": 32px
  "10": 40px
  "12": 48px
  "16": 64px
  "24": 96px
  target: 44px
  target-driver: 56px
  topbar: 56px
  tabbar: 64px
  sidebar: 248px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    height: 44px
    padding: 0 20px
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-primary}"
  button-driver:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.pill}"
    height: 56px
    padding: 0 32px
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    height: 44px
  button-signal:
    backgroundColor: "{colors.signal}"
    textColor: "{colors.on-signal}"
    rounded: "{rounded.pill}"
    height: 44px
  button-danger:
    backgroundColor: "{colors.stop-fill}"
    textColor: "{colors.on-stop-fill}"
    rounded: "{rounded.pill}"
    height: 44px
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    height: 44px
    padding: 0 16px
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: 20px
  nav-item-active:
    backgroundColor: "{colors.primary-tint}"
    textColor: "{colors.on-primary-tint}"
    rounded: "{rounded.sm}"
  badge-go:
    backgroundColor: "{colors.go-bg}"
    textColor: "{colors.go}"
    rounded: "{rounded.pill}"
  badge-caution:
    backgroundColor: "{colors.caution-bg}"
    textColor: "{colors.caution}"
    rounded: "{rounded.pill}"
  badge-stop:
    backgroundColor: "{colors.stop-bg}"
    textColor: "{colors.stop}"
    rounded: "{rounded.pill}"
  safety-banner:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.ground}"
    rounded: "{rounded.md}"
  safety-call:
    backgroundColor: "{colors.signal}"
    textColor: "{colors.on-signal}"
    rounded: "{rounded.pill}"
    height: 44px
  statebar-online:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.state-title}"
  night-button-primary:
    backgroundColor: "{colors.night-primary}"
    textColor: "{colors.night-on-primary}"
    rounded: "{rounded.pill}"
  night-panel:
    backgroundColor: "{colors.night-surface}"
    textColor: "{colors.night-ink}"
    rounded: "{rounded.md}"
  skeleton:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
  switch-track-off:
    backgroundColor: "{colors.surface-3}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
  text-secondary:
    backgroundColor: "{colors.ground}"
    textColor: "{colors.ink-2}"
  caption-text:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink-3}"
  divider:
    backgroundColor: "{colors.rule}"
    textColor: "{colors.ink}"
  field-border:
    backgroundColor: "{colors.rule-strong}"
    textColor: "{colors.on-signal}"
  badge-info:
    backgroundColor: "{colors.info-bg}"
    textColor: "{colors.info}"
    rounded: "{rounded.pill}"
  focus-ring:
    backgroundColor: "{colors.focus}"
    textColor: "{colors.on-primary}"
  logo-disc:
    backgroundColor: "{colors.brand-yellow}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
  logo-chevron:
    backgroundColor: "{colors.brand-green}"
    textColor: "{colors.ink}"
  map-base:
    backgroundColor: "{colors.map-land}"
    textColor: "{colors.map-label}"
  map-road-minor:
    backgroundColor: "{colors.map-road}"
    textColor: "{colors.map-label}"
  map-road-edge:
    backgroundColor: "{colors.map-road-casing}"
    textColor: "{colors.ink}"
  map-road-arterial:
    backgroundColor: "{colors.map-road-major}"
    textColor: "{colors.ink}"
  map-water-area:
    backgroundColor: "{colors.map-water}"
    textColor: "{colors.ink}"
  map-park-area:
    backgroundColor: "{colors.map-park}"
    textColor: "{colors.ink}"
  map-building-area:
    backgroundColor: "{colors.map-building}"
    textColor: "{colors.ink}"
  map-route-line:
    backgroundColor: "{colors.map-route}"
    textColor: "{colors.on-primary}"
  map-pickup-marker:
    backgroundColor: "{colors.map-pickup}"
    textColor: "{colors.on-signal}"
    rounded: "{rounded.pill}"
  map-dropoff-marker:
    backgroundColor: "{colors.map-dropoff}"
    textColor: "{colors.on-primary}"
  map-driver-marker:
    backgroundColor: "{colors.map-driver}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.pill}"
  night-page:
    backgroundColor: "{colors.night-ground}"
    textColor: "{colors.night-ink}"
  night-panel-quiet:
    backgroundColor: "{colors.night-surface-2}"
    textColor: "{colors.night-ink-2}"
  night-caption:
    backgroundColor: "{colors.night-ground}"
    textColor: "{colors.night-ink-3}"
  night-error-text:
    backgroundColor: "{colors.night-surface}"
    textColor: "{colors.night-stop}"
  night-focus-ring:
    backgroundColor: "{colors.night-focus}"
    textColor: "{colors.night-ground}"
  night-map-base:
    backgroundColor: "{colors.night-map-land}"
    textColor: "{colors.night-ink-2}"
  night-map-road-minor:
    backgroundColor: "{colors.night-map-road}"
    textColor: "{colors.night-ink}"
  night-map-water-area:
    backgroundColor: "{colors.night-map-water}"
    textColor: "{colors.night-ink}"
  night-map-park-area:
    backgroundColor: "{colors.night-map-park}"
    textColor: "{colors.night-ink}"
---

# Route Line: the Dot.Ehail design system

Source of truth for tokens: `resources/css/ds/tokens.css` (`--eh-*` custom properties, light by default, night under `prefers-color-scheme: dark` or `data-theme="dark"`). This file describes them; when the two disagree, the CSS is what ships and this file is corrected in the same commit.

## Overview

A ride is a line between two stops. Dot.Ehail draws everything from that: the logo's yellow disc is the stop, its green chevron is the direction of travel, and the product's promise ("you see the whole fare before you request") is shown as a route line whose stops are the parts of the fare, ending in the yellow stop that is your total. The same line runs down the side of the sign-in page (Your journey, Your safety, Your choice), through the driver's state bar, and through every empty state (a dashed line while a destination is still being built, solid when the person simply has nothing yet).

The world comes from South African roads, not from ride-hailing apps: the green of national direction signs with white lettering, the yellow of road markings and kerb lines, Barlow (a typeface descended from highway and licence-plate lettering), and transit-map stops. It refuses the category's two defaults: black-and-white minimalism and neon-on-black.

Modes: the app shells are Operate surfaces (the tool disappears into the task; colour on small marks and pale tints, flat surfaces, no decoration). The landing page is a Persuade surface (larger type, photographs of South African roads, whole fields of colour in the footer).

## Colors

Strategy: Restrained in the apps (3 to 10 percent colour, nearly all as pale tints of sign green), Committed on the landing footer (a whole field of sign green).

- **Sign green (primary #0b6a3c, night #48b17b):** the one action colour. Primary buttons, the active tab indicator, route lines, the online state. Never decoration.
- **Green tint (#d9eedf / night #24493a):** area work: the active navigation row, the open fare explanation, selected chips.
- **Road-marking yellow (signal #f2c230):** the stop: pickup marker, current step, destination dot, emergency call buttons, text selection. Always carries dark ink; never text on light ground.
- **Map paper (ground #f1f4f0):** a cool, slightly green paper. Not cream. Surfaces step by tone (surface, surface-2, surface-3), never by shadow.
- **State:** go, caution, stop and info each come as a text colour plus a background tint, always paired with a word or icon.
- **Focus (#1d5bd6 / night #8fbaff):** a 3px ring, at least 3:1 against every ground.
- **Brand marks (#f1c62f, #9dcb86):** the logo only, never interface colour.
- **Map palette:** land, roads (major roads in pale marking yellow), water, parks, buildings, labels, route (sign green), pickup (yellow), drop-off (ink), driver (focus blue). The geo agent's map style reads `--eh-map-*`; night values swap automatically.

Every text pair is measured in `docs/R46_DS.md`: body ink 15.4:1 (light) and 12.9:1 (night); secondary inks above 4.9:1; white on sign green 6.7:1; field borders 3.2:1 and up.

**Night theme.** Default for the driver app between 18:00 and 06:00 (or whenever the phone is dark): a car interior at night needs less glare and an OLED phone saves battery; by day the driver app follows the phone, because sun on a windscreen needs the light theme. Passengers and operators follow the phone. Everyone can pin Light or Dark in the account menu.

## Typography

Barlow for everything people read and operate (400, 500, 600, 700); Barlow Condensed (600, 700) for display: page titles on the landing, the driver's state ("You are online"), large money and ETA figures. Self-hosted from `public/fonts/` (SIL OFL), Latin subset, about 22 KB per weight, two weights preloaded. Numbers in money, ETAs, PINs and tables use tabular lining figures.

App scale is fixed rem with a tight ratio (13, 14, 16, 18, 22, 28, 36, 48 px); the landing uses five steps plus the two display sizes. Floor is 13px. Sentence case everywhere; no tracked capitals; no monospace except keyboard keys.

## Layout

4px base spacing. Touch targets at least 44px; driver primary actions 56px. Passenger and driver apps are mobile-first: 56px top bar, content, 64px (driver 72px) bottom tab bar on phones; on tablets and desktop the tabs move into the top bar. Work surfaces (ops, business, fleet) use a 248px left navigation that becomes a drawer below 1024px. Content measure 68ch for prose. The landing uses a 12px outer gap with a capped 88rem container.

## Elevation & Depth

Flat at rest. Surfaces separate by a tone step. Only things that float get a shadow (`--eh-shadow-float`): menus, dialogs, toasts, the bottom sheet, the open drawer. No glows, no glass, no gradients.

## Shapes

Controls are pills (a route line has round caps). Panels use 12px corners, landing photographs 20px, stops and avatars are discs. One radius per nesting level.

## Components

`<x-eh.*>` Blade components (catalogue and props in `docs/R46_DS.md`): button (sm 36, md 44, lg 48, driver 56; primary, secondary, ghost, signal, danger; link or button; loading and disabled states), icon-button, icon (114 icons, Lucide ISC plus own drawings for car classes, steering wheel, licence disc, pickup and drop-off, route line, online and offline, SOS, ride PIN, fleet, kerb), field, select, switch (visible track and a text state), segmented, tabs (navigation or ARIA tablist), chip, badge, status (maps the ride lifecycle, old and new names), money (integer minor units, ZAR by default), eta, stepper (the route line), list-row, empty, skeleton, toasts, dialog (native `<dialog>`), banner (including the safety banner with 10111, 10177 and 112 as calls), avatar (initials on a pale identity tint, never a remote image), rating-stars (display only), map-frame (the container the geo map mounts in), stat-line (figures in a row, never tiles), kbd, logo, sheet (bottom sheet with peek, half and full snaps, keyboard and reduced-motion safe; docks as a side panel on desktop).

Layouts: `<x-passenger-layout>`, `<x-driver-layout>`, `<x-ops-layout>`, `<x-business-layout>`, `<x-fleet-layout>`, `<x-guest-layout>`; navigation comes from `App\Support\Ui\Navigation`, which shows only destinations whose routes exist.

## Do's and Don'ts

- Do show the number before the ask: fares, earnings, waiting time and fees appear before any commitment.
- Do keep safety in the frame: every passenger and driver screen has the safety control in the top bar.
- Do say plainly when something is not there yet (an empty state with a dashed line and what will land here).
- Do use tabular figures for money and time, and format money from integer minor units only.
- Don't use colour alone for state: every badge carries a word, every switch says On or Off with its track.
- Don't put a shadow on anything at rest, a coloured stripe on a card's side, gradient text, glass or glows.
- Don't use metric tiles in a row of four; use a stat line or a table.
- Don't load anything from another host at runtime: fonts, icons and images live in `public/`.
- Don't use em or en dashes in interface copy.
- Don't invent numbers, riders, drivers, ratings or testimonials, in the UI or on the landing page.
