<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Driver app shell (/drive): <x-driver-layout title="..."> (ds, R-46). See resources/views/layouts/driver.blade.php for slots. */
class DriverLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $bleed = false,
        public ?string $scope = null,
    ) {}

    public function render(): View
    {
        return view('layouts.driver');
    }
}
