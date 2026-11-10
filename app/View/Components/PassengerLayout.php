<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Passenger app shell (/ride): <x-passenger-layout title="..."> (ds, R-46). See resources/views/layouts/passenger.blade.php for slots. */
class PassengerLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $bleed = false,
        public ?string $scope = null,
    ) {}

    public function render(): View
    {
        return view('layouts.passenger');
    }
}
