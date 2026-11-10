/** Pure helpers for the public trip-share page, kept apart from the DOM so they can be tested. */

/** The line under the car details: how exact the map position is and when the car was last seen. */
export function positionNote(payload) {
    const spot = payload?.approximate_position;

    if (!spot) {
        return "The car's position is not available right now.";
    }

    const seen = spot.time ? ` Last seen ${spot.time}${payload.time_zone ? ` ${payload.time_zone}` : ''}.` : '';

    return `The car's position on the map is approximate, to about 100\u00a0m.${seen}`;
}

/** True when the open page no longer matches the trip: it ended, or its route changed, so the server page is the truth. */
export function shouldReload(first, next) {
    if (!first || !next) return false;

    return Boolean(next.ended) !== Boolean(first.ended) || (next.route_key ?? null) !== (first.route_key ?? null);
}
