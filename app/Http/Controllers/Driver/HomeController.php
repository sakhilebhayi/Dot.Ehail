<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Dispatch\RideFeed;
use App\Domain\Earnings\EarningsService;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/drive`: the driver's one screen while working. Not registered: the way in. Registered but not approved: the
 * onboarding checklist. Approved: the live console (go online, offers, the trip), driven by the dispatch feed.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, RideFeed $feed, EarningsService $earnings): View
    {
        $state = DriverState::for($request->user());
        $profile = $state->profile();

        if ($profile === null) {
            return view('driver.join');
        }

        if (! $state->isApproved()) {
            return view('driver.onboarding', ['state' => $state, 'documents' => $state->documents(), 'eligibility' => $state->eligibility()]);
        }

        // A driver who has never been online has no feed sources yet (version 0, no body): start from "offline".
        $body = $feed->forDriver($request->user())['body'] ?? ['version' => 0, 'availability' => ['state' => 'offline'], 'offer' => null, 'ride' => null];
        $today = $earnings->forDriver($request->user(), 'day');

        return view('driver.console', [
            'state' => $state,
            'eligibility' => $state->eligibility(),
            'feed' => $body,
            'today' => $today['totals'],
            'currency' => $today['currency'],
            'boot' => $this->boot($state, $body),
        ]);
    }

    /**
     * Everything the console script needs at start: the first feed, policies and endpoint URLs. No secrets.
     *
     * @param  array<string, mixed>|null  $feed
     * @return array<string, mixed>
     */
    private function boot(DriverState $state, ?array $feed): array
    {
        $vehicle = $state->activeVehicle();
        $routing = false;

        try {
            $routing = app(RoutePlanner::class)->isConfigured();
        } catch (\Throwable) {
            $routing = false;
        }

        return [
            'feed' => $feed,
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'label' => trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}"), 'plate' => $vehicle->plate_number] : null,
            'policy' => [
                'offer_timeout_s' => (int) config('ehail_dispatch.offer_timeout_seconds'),
                'no_show_wait_min' => (int) config('ehail_dispatch.no_show_wait_minutes'),
                'arrival_tolerance_m' => (int) config('ehail_driver.arrival.tolerance_m'),
                'arrival_accuracy_cap_m' => (int) config('ehail_driver.arrival.accuracy_allowance_cap_m'),
                'destination_detail' => config('ehail_driver.offer_destination_detail') === 'address' ? 'address' : 'area',
                'post_every_s' => (int) config('ehail_driver.location.post_every_s'),
                'saver_post_every_s' => (int) config('ehail_driver.location.saver_post_every_s'),
                'heartbeat_every_s' => (int) config('ehail_driver.location.heartbeat_every_s'),
                'max_batch' => (int) config('ehail_driver.location.max_batch'),
                'heartbeat_stale_s' => (int) config('ehail_dispatch.heartbeat_stale_seconds'),
                'moving' => config('ehail_driver.moving'),
                'routing_configured' => $routing,
            ],
            'urls' => [
                'feed' => route('ehail.dispatch.driver.feed'),
                'online' => route('ehail.dispatch.driver.online'),
                'offline' => route('ehail.dispatch.driver.offline'),
                'pause' => route('ehail.dispatch.driver.pause'),
                'resume' => route('ehail.dispatch.driver.resume'),
                'heartbeat' => route('ehail.dispatch.driver.heartbeat'),
                'location' => route('geo.driver.location'),
                'route' => route('geo.route'),
                'offer_seen' => route('ehail.dispatch.offers.seen', ['offer' => '__ID__']),
                'offer_accept' => route('ehail.dispatch.offers.accept', ['offer' => '__ID__']),
                'offer_decline' => route('ehail.dispatch.offers.decline', ['offer' => '__ID__']),
                'offer_earnings' => route('drive.api.offer-earnings', ['offer' => '__ID__']),
                'stage' => route('ehail.dispatch.driver.rides.stage', ['ride' => '__ID__', 'stage' => '__STAGE__']),
                'arrived' => route('drive.api.arrived', ['ride' => '__ID__']),
                'no_show' => route('ehail.dispatch.driver.rides.no-show', ['ride' => '__ID__']),
                'cancel' => route('ehail.dispatch.driver.rides.cancel', ['ride' => '__ID__']),
                'summary' => route('drive.api.summary', ['ride' => '__ID__']),
                'cash' => route('money.driver.cash-received', ['ride' => '__ID__']),
                'sos' => route('safety.rides.sos', ['ride' => '__ID__']),
                'trip' => route('drive.trips.show', ['ride' => '__ID__']),
            ],
        ];
    }
}
