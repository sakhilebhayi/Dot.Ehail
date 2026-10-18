<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RideController extends Controller
{
    /**
     * Searchable ride list, limited to the rides the user may see (their
     * own, their fleet's, or everything for a platform operator) -- the
     * search only ever runs inside that set.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Ride::class);

        $search = trim((string) $request->get('q', ''));

        $rides = Ride::visibleTo($request->user())
            ->with(['driver', 'passenger', 'vehicle'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('pickup_address', 'like', "%{$search}%")
                        ->orWhere('dropoff_address', 'like', "%{$search}%")
                        ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('passenger', fn ($p) => $p->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('rides.index', [
            'rides' => $rides,
            'search' => $search,
        ]);
    }

    /**
     * Show a single ride. Authorization is delegated to RidePolicy.
     */
    public function show(Ride $ride): View
    {
        Gate::authorize('view', $ride);

        $ride->load(['driver', 'passenger', 'vehicle', 'rating']);

        return view('rides.show', [
            'ride' => $ride,
        ]);
    }
}
