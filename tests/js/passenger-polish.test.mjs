// R-46 passenger polish: place names, the day-and-time picker's slots, the WhatsApp hand-off and the public
// trip-share page's pure state. Run with: node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { shortPlace, splitPlace } from '../../resources/js/passenger/places.js';
import { ceilToStep, dayKey, localValue, whenDays, whenLabel, whenSlots } from '../../resources/js/eh/datetime.js';
import { whatsappUrl } from '../../resources/js/passenger/safety.js';
import { positionNote, shouldReload } from '../../resources/js/share/state.js';

// ------------------------------------------------------------------ place names
test('a place reads as a name and the area it is in, without the postcode or the country', () => {
    assert.deepEqual(splitPlace('Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa'), {
        name: 'Sandton City', detail: 'Rivonia Road, Sandhurst', short: 'Sandton City, Rivonia Road',
    });
    assert.equal(shortPlace('Rosebank Mall, 50 Bath Avenue, Rosebank, Johannesburg, 2196'), 'Rosebank Mall, 50 Bath Avenue');
});

test('a house number given on its own joins its street, and a bare name stays as it is', () => {
    assert.equal(splitPlace('12, Kloof Street, Gardens, Cape Town, 8001').name, '12 Kloof Street');
    assert.deepEqual(splitPlace('OR Tambo International Airport'), { name: 'OR Tambo International Airport', detail: '', short: 'OR Tambo International Airport' });
    assert.deepEqual(splitPlace(''), { name: '', detail: '', short: '' });
    assert.deepEqual(splitPlace(null), { name: '', detail: '', short: '' });
});

// ------------------------------------------------------------------ day and time picker
test('the picker offers only times inside the booking window, on the step grid', () => {
    const min = new Date(2026, 10, 10, 14, 7);
    const max = new Date(2026, 10, 12, 9, 0);

    assert.equal(localValue(ceilToStep(min, 15)), '2026-11-10T14:15');
    const today = whenSlots('2026-11-10', min, max, 15);
    assert.equal(today[0], '14:15');
    assert.equal(today.at(-1), '23:45');
    assert.equal(whenSlots('2026-11-11', min, max, 15).length, 96);
    assert.deepEqual(whenSlots('2026-11-12', min, max, 15).slice(-2), ['08:45', '09:00']);
    assert.deepEqual(whenSlots('2026-11-13', min, max, 15), []);
});

test('days are named Today and Tomorrow, then by weekday, and a day with no time left is not offered', () => {
    const now = new Date(2026, 10, 10, 23, 50);
    const days = whenDays(now, new Date(2026, 10, 13, 12, 0), 15, now);

    // 23:50 rounds up to midnight: today has no slot left, so the list starts tomorrow.
    assert.deepEqual(days.map((d) => d.key), ['2026-11-11', '2026-11-12', '2026-11-13']);
    assert.equal(days[0].label, 'Tomorrow');
    assert.equal(days[0].day, 11);
    assert.notEqual(days[1].label, 'Tomorrow');

    const earlier = new Date(2026, 10, 10, 9, 0);
    assert.equal(whenDays(earlier, new Date(2026, 10, 11, 9, 0), 15, earlier)[0].label, 'Today');
    assert.equal(dayKey(earlier), '2026-11-10');
});

test('the chosen time is summarised in words', () => {
    const now = new Date(2026, 10, 10, 9, 0);
    assert.equal(whenLabel('2026-11-10T17:30', now), 'Today, 17:30');
    assert.equal(whenLabel('2026-11-11T07:05', now), 'Tomorrow, 07:05');
    assert.match(whenLabel('2026-11-14T07:05', now), /14.*07:05$/);
    assert.equal(whenLabel('', now), '');
    assert.equal(whenLabel('not a date', now), '');
});

// ------------------------------------------------------------------ WhatsApp hand-off
test('the WhatsApp link carries a plain message and the trip link, encoded, and no phone number', () => {
    const link = whatsappUrl('https://ehail.example/t/abc123');
    assert.equal(link, 'https://wa.me/?text=Follow%20my%20Dot.Ehail%20trip%20live%3A%20https%3A%2F%2Fehail.example%2Ft%2Fabc123');
    assert.equal(whatsappUrl('', 'I need help'), 'https://wa.me/?text=I%20need%20help');
});

// ------------------------------------------------------------------ public trip-share page
test('the share page says how exact the car position is and when it was last seen', () => {
    assert.equal(
        positionNote({ approximate_position: { lat: -26.146, lng: 28.041, time: '14:30:05' }, time_zone: 'SAST' }),
        "The car's position on the map is approximate, to about 100 m. Last seen 14:30:05 SAST.",
    );
    assert.equal(positionNote({ approximate_position: { lat: -26.146, lng: 28.041 } }), "The car's position on the map is approximate, to about 100 m.");
    assert.equal(positionNote({ approximate_position: null }), "The car's position is not available right now.");
    assert.equal(positionNote(null), "The car's position is not available right now.");
});

test('the share page reloads when the trip ends or its route changes, and not otherwise', () => {
    const first = { ended: false, route_key: 'aaa111' };
    assert.equal(shouldReload(first, { ended: false, route_key: 'aaa111', state: 'in_progress' }), false);
    assert.equal(shouldReload(first, { ended: true, route_key: 'aaa111' }), true);
    assert.equal(shouldReload(first, { ended: false, route_key: 'bbb222' }), true);
    assert.equal(shouldReload(null, { ended: true }), false);
    assert.equal(shouldReload(first, null), false);
});
