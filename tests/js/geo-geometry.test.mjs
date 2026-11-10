import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bearing, boundsOf, decodePolyline, encodePolyline, formatDistance, formatDuration, haversine, interpolateHeading, pathLength, pointAlong } from '../../resources/js/geo/geometry.js';

test('polyline decodes and encodes Google\'s reference example', () => {
    const points = decodePolyline('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
    assert.deepEqual(points, [
        { lat: 38.5, lng: -120.2 },
        { lat: 40.7, lng: -120.95 },
        { lat: 43.252, lng: -126.453 },
    ]);
    assert.equal(encodePolyline(points), '_p~iF~ps|U_ulLnnqC_mqNvxq`@');
});

test('polyline matches the PHP encoder for South African coordinates at precision 6', () => {
    const points = [{ lat: -33.924869, lng: 18.424055 }, { lat: -33.971463, lng: 18.602085 }];
    const round = decodePolyline(encodePolyline(points, 6), 6);
    round.forEach((p, i) => {
        assert.ok(Math.abs(p.lat - points[i].lat) < 1e-6);
        assert.ok(Math.abs(p.lng - points[i].lng) < 1e-6);
    });
});

test('malformed polylines throw instead of drawing garbage', () => {
    assert.throws(() => decodePolyline('_p~iF~ps|U_'), /Truncated/);
    assert.throws(() => decodePolyline('_p~iF ps|U'), /Invalid/);
});

test('haversine and bearing', () => {
    const jhb = { lat: -26.2041, lng: 28.0473 };
    const pta = { lat: -25.7479, lng: 28.2293 };
    assert.ok(Math.abs(haversine(jhb, pta) - 53800) < 800);
    assert.ok(Math.abs(bearing({ lat: -26, lng: 28 }, { lat: -26, lng: 28.1 }) - 90) < 0.1);
});

test('heading interpolation takes the short way round', () => {
    assert.equal(interpolateHeading(350, 10, 0.5), 0);
    assert.equal(interpolateHeading(10, 350, 0.5), 0);
    assert.equal(interpolateHeading(90, 180, 0.5), 135);
});

test('pointAlong walks a path and reports the segment heading', () => {
    const path = [{ lat: -26, lng: 28 }, { lat: -26, lng: 28.01 }, { lat: -25.99, lng: 28.01 }];
    const total = pathLength(path);
    const start = pointAlong(path, 0);
    const middleOfFirst = pointAlong(path, haversine(path[0], path[1]) / 2);
    const end = pointAlong(path, total + 100);

    assert.deepEqual({ lat: start.lat, lng: start.lng }, path[0]);
    assert.ok(Math.abs(middleOfFirst.lng - 28.005) < 1e-6);
    assert.ok(Math.abs(middleOfFirst.heading - 90) < 0.1);
    assert.ok(Math.abs(end.lat - path[2].lat) < 1e-9);
    assert.ok(end.heading < 1 || end.heading > 359);
    assert.equal(pointAlong([], 10), null);
});

test('bounds ignore missing points', () => {
    assert.deepEqual(boundsOf([{ lat: -26, lng: 28 }, null, { lat: -25, lng: 27 }]), [[-26, 27], [-25, 28]]);
    assert.equal(boundsOf([null]), null);
});

test('distance and duration formatting', () => {
    assert.equal(formatDistance(847), '850 m');
    assert.equal(formatDistance(4230), '4.2 km');
    assert.equal(formatDistance(23400), '23 km');
    assert.equal(formatDuration(30), '1 min');
    assert.equal(formatDuration(612), '10 min');
    assert.equal(formatDuration(3900), '1 h 05 min');
});
