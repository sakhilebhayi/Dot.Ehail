// What the live ride page says for each state of the ride (docs/R46_DISPATCH.md section 1). Pure; the server
// renders the same words for the first paint (App\Domain\Passenger\RideText), the feed keeps them current.

import { distanceWords } from '../eh/announce.js';
import { haversine } from '../geo/geometry.js';

export const STAGES = ['Finding a driver', 'Driver on the way', 'At pickup', 'On the trip', 'Arrived'];

const HOLDS = ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];
const TERMINAL = ['completed', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed'];

export const holdsDriver = (status) => HOLDS.includes(status);
export const isTerminal = (status) => TERMINAL.includes(status);
export const isSearching = (status) => status === 'requested' || status === 'searching';
export const isPreTrip = (status) => isSearching(status) || ['driver_assigned', 'driver_arriving', 'driver_arrived'].includes(status);

export function stageIndex(status) {
    switch (status) {
        case 'requested':
        case 'searching':
            return 0;
        case 'driver_assigned':
        case 'driver_arriving':
            return 1;
        case 'driver_arrived':
            return 2;
        case 'in_progress':
            return 3;
        case 'completed':
            return 4;
        default:
            return -1;
    }
}

function minutes(seconds) {
    return Math.max(1, Math.round(seconds / 60));
}

function shortPlace(address) {
    const text = String(address || '').split(',')[0].trim();
    return text || 'your destination';
}

/**
 * {title, sub} for the ride and the driver position the feed carries.
 */
export function rideText(ride, position = null) {
    const name = ride?.driver?.first_name || 'Your driver';
    const eta = Number.isFinite(position?.eta_s) ? minutes(position.eta_s) : null;
    const estimate = position?.eta_method && position.eta_method !== 'road' ? 'about ' : '';
    switch (ride?.status) {
        case 'scheduled':
            return {
                title: 'Ride booked',
                sub: ride.schedule_phase === 'driver_confirmed' ? 'A driver has confirmed your ride.' : 'Awaiting driver. We start looking shortly before your pickup time.',
            };
        case 'requested':
        case 'searching':
            return { title: 'Finding a driver', sub: 'We offer your ride to nearby drivers one at a time. Cancelling now is free.' };
        case 'driver_assigned':
        case 'driver_arriving':
            return { title: `${name} is on the way`, sub: eta ? `Arrives in ${estimate}${eta} min.` : 'Arrival time shows once the driver’s phone reports a position.' };
        case 'driver_arrived':
            return { title: `${name} is here`, sub: 'Meet your driver at the pickup. Check the car and registration first.' };
        case 'in_progress':
            return { title: `On the way to ${shortPlace(ride.dropoff?.address)}`, sub: eta ? `Arriving in ${estimate}${eta} min.` : 'Enjoy the ride. Your trip is being followed.' };
        case 'completed':
            return { title: 'You have arrived', sub: 'Thank you for riding with Dot.Ehail.' };
        case 'cancelled_by_passenger':
            return { title: 'You cancelled this ride', sub: ride.fare?.cancellation_fee_minor ? 'A cancellation fee applies under the fare rules.' : 'No fee was charged.' };
        case 'cancelled_by_driver':
            return { title: 'The driver cancelled', sub: 'We could not find another driver for this ride. You are not charged.' };
        case 'cancelled_by_operator':
            return { title: 'Cancelled by Dot.Ehail', sub: 'Our operations team cancelled this ride. Ask support if you need to know why.' };
        case 'expired':
            return { title: 'No driver found', sub: 'No driver accepted in time. You are not charged.' };
        case 'no_show':
            return { title: 'Marked as a no-show', sub: 'The driver waited at the pickup for the free waiting time. A no-show fee may apply under the fare rules.' };
        case 'failed':
            return { title: 'This ride could not go ahead', sub: 'Something went wrong on our side. You are not charged for a trip that did not happen.' };
        case 'needs_review':
            return { title: 'Under review', sub: 'Our operations team is checking this ride. We will tell you the outcome.' };
        default:
            return { title: ride?.status_label || 'Your ride', sub: '' };
    }
}

/** A sentence for screen readers when the state changes (assertive for the moments that need action). */
export function announcement(previous, ride, position) {
    if (!ride || previous === ride.status) return null;
    const { title, sub } = rideText(ride, position);
    const urgent = ['driver_arrived', 'cancelled_by_driver', 'expired', 'failed'].includes(ride.status);
    return { text: `${title}. ${sub}`.trim(), assertive: urgent };
}

/**
 * The map in words (D-1704): what a sighted person reads off the map, for screen readers and anyone who cannot use
 * it. Pickup, drop-off, and where the driver is relative to the next stop, with the arrival time when known.
 */
export function mapSummary(ride, position = null) {
    if (!ride) return '';
    const parts = [];
    if (ride.pickup?.address) parts.push(`Pickup: ${shortPlace(ride.pickup.address)}.`);
    if (ride.dropoff?.address) parts.push(`Drop-off: ${shortPlace(ride.dropoff.address)}.`);
    const onTrip = ride.status === 'in_progress';
    const target = onTrip ? ride.dropoff : ride.pickup;
    if (holdsDriver(ride.status) && position && Number.isFinite(position.lat) && Number.isFinite(target?.lat)) {
        const far = distanceWords(haversine(position, target));
        const eta = Number.isFinite(position.eta_s) ? ` Arriving in ${position.eta_method && position.eta_method !== 'road' ? 'about ' : ''}${minutes(position.eta_s)} min.` : '';
        parts.push(onTrip ? `You are ${far} from the drop-off in a straight line.${eta}` : `Your driver is ${far} from the pickup in a straight line.${eta}`);
        if (position.stale) parts.push('The driver position is not current.');
    } else if (holdsDriver(ride.status)) {
        parts.push('The driver position is not available yet.');
    }

    return parts.join(' ');
}

/** The driver's rating as a band, never a decimal (docs/R46_SAFETY.md "Ratings"). */
export function ratingBand(rating) {
    if (rating?.label) return rating.label;
    return 'New on Dot.Ehail';
}
