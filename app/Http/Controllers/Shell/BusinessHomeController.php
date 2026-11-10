<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** `/business` shell placeholder (ds agent, R-46 wave 1). Business accounts are built in wave 2. */
class BusinessHomeController extends Controller
{
    public function __invoke(): View
    {
        return view('shell.business-home');
    }
}
