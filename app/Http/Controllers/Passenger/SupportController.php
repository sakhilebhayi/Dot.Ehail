<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Help pages: the passenger's cases, a new case (lost property, fare dispute, anything else) and one case's
 * thread. The pages read and post through the safety domain's support API (/support/*); the case list and thread
 * load from it so masking and visibility stay that domain's decision.
 */
class SupportController extends Controller
{
    public function index(): View
    {
        return view('passenger.support.index');
    }

    public function create(Request $request): View
    {
        $rides = Ride::query()->where('passenger_id', $request->user()->id)->orderByDesc('id')->limit(20)->get();
        $category = $request->query('category');

        return view('passenger.support.create', [
            'rides' => $rides,
            'categories' => array_values(array_filter(SupportCategory::cases(), fn (SupportCategory $c) => $c !== SupportCategory::PassengerConduct)),
            'selectedCategory' => is_string($category) && SupportCategory::tryFrom($category) ? $category : null,
            'selectedRide' => $rides->firstWhere('id', (int) $request->query('ride')),
        ]);
    }

    public function show(Request $request, SupportCase $case, SupportCaseService $cases): View
    {
        $role = $cases->roleOf($case, $request->user());
        abort_if($role === null || $role === 'agent', 404);

        return view('passenger.support.show', ['case' => $case]);
    }
}
