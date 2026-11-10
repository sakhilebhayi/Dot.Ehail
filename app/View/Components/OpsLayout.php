<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Operations shell (/ops): <x-ops-layout title="..."> (ds, R-46). See resources/views/layouts/ops.blade.php for slots. */
class OpsLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $bleed = false,
        public ?string $scope = null,
    ) {}

    public function render(): View
    {
        return view('layouts.ops');
    }
}
