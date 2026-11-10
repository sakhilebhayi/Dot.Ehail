// Dot.Ehail operations centre (R-46 ops). Small entry: action forms, timers and chart tooltips; the live map and the
// zone editor load their own chunks (with the geo map module) only on the pages that have them.
import '../../css/ops.css';
import { initActions } from './actions.js';
import { initTimers } from './timers.js';
import { initCharts } from './charts.js';

initActions();
initTimers();
initCharts();

if (document.querySelector('[data-ops-live]')) {
    import('./live.js').then(({ startLive }) => startLive(document.querySelector('[data-ops-live]')));
}

if (document.querySelector('[data-ops-zones]')) {
    import('./zones.js').then(({ startZones }) => startZones(document.querySelector('[data-ops-zones]')));
}

if (document.querySelector('[data-ops-ride-map]')) {
    import('./ride-map.js').then(({ startRideMap }) => startRideMap(document.querySelector('[data-ops-ride-map]')));
}

if (document.querySelector('form[data-ops-simulate]')) {
    import('./simulate.js').then(({ initSimulator }) => initSimulator(document.querySelector('form[data-ops-simulate]')));
}
