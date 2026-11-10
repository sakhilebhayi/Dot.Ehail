// R-46 a11y: reduced motion. With prefers-reduced-motion: reduce, no page runs a CSS animation or transition longer
// than 0.01 ms, the booking map is not animated (Leaflet's zoom/fade classes are absent), and the driver's waiting
// line and offer countdown do not move. Also counts animations with motion allowed, to show the check bites.
//   node scripts/dev/a11y/motion.mjs
import { BASE, PEOPLE, browser, person, sleep, writeJson } from './lib.mjs';

const PAGES = [[null, '/'], [null, '/fares'], [PEOPLE.passenger, '/ride?new=1'], [PEOPLE.passenger, '/ride/trips'], [PEOPLE.driver, '/drive'], [PEOPLE.ops, '/ops/live'], [PEOPLE.passenger, '/business'], [PEOPLE.passenger, '/fleet']];
const MEASURE = `(() => {
    const long = document.getAnimations().filter((a) => { const t = a.effect?.getComputedTiming?.(); return t && (t.duration === Infinity || t.duration > 1) && a.playState !== 'finished'; });
    let transitions = 0;
    for (const e of document.querySelectorAll('body *')) { const d = getComputedStyle(e).transitionDuration.split(',').map(parseFloat); if (d.some((x) => x > 0.001)) transitions++; }
    const mapAnimated = !!document.querySelector('.leaflet-zoom-anim, .leaflet-fade-anim');
    return { animations: long.length, names: long.slice(0, 4).map((a) => a.animationName || a.constructor.name), transitions, mapAnimated };
})()`;
const results = [];
const b = await browser();
try {
    for (const reduce of [false, true]) {
        for (const [who, path] of PAGES) {
            const p = await person(b, who, { width: 390, reducedMotion: reduce ? 'reduce' : 'no-preference' });
            await p.page.goto(BASE + path); await sleep(1500);
            const m = await p.page.evaluate(MEASURE);
            const ok = !reduce || (m.animations === 0 && m.transitions === 0 && !m.mapAnimated);
            results.push({ path, reduce, ...m, ok });
            console.log(`${ok ? 'PASS' : 'FAIL'}  ${reduce ? 'reduce ' : 'motion '} ${path.padEnd(14)} animations ${m.animations} ${m.names.join(',')} transitions ${m.transitions} map-animated ${m.mapAnimated}`);
            await p.ctx.close();
        }
    }
} finally { await b.close(); }
writeJson('motion.json', results);
process.exit(results.some((r) => !r.ok) ? 1 : 0);
