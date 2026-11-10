<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Business accounts shell (/business): <x-business-layout title="..."> (ds, R-46). See resources/views/layouts/business.blade.php for slots. */
class BusinessLayout extends Component
{
    public function __construct(
        public ?string $title = null,
        public bool $bleed = false,
        public ?string $scope = null,
    ) {}

    public function render(): View
    {
        return view('layouts.business');
    }
}
