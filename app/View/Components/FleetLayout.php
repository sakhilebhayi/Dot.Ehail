<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Fleet operator shell (/fleet): <x-fleet-layout title="..."> (ds, R-46). See resources/views/layouts/fleet.blade.php for slots. */
class FleetLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $bleed = false,
        public ?string $scope = null,
    ) {}

    public function render(): View
    {
        return view('layouts.fleet');
    }
}
