<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** `/ops` shell placeholder (ds agent, R-46 wave 1); operators only (route middleware). Wave 2 replaces it. */
class OpsHomeController extends Controller
{
    public function __invoke(): View
    {
        return view('shell.ops-home');
    }
}
