<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * `/ride` shell placeholder (ds agent, R-46 wave 1). The wave-2 passenger app replaces this controller; until then
 * the page says plainly that booking opens here and points at the request form that works today.
 */
class PassengerHomeController extends Controller
{
    public function __invoke(): View
    {
        return view('shell.passenger-home');
    }
}
