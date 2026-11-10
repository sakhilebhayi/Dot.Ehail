<?php

namespace App\Http\Controllers\Shell;

use App\Http\Controllers\Controller;
use App\Support\Ui\RoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** `/home`: sends a person to the surface their role belongs in (also the PWA start URL). */
class RoleHomeController extends Controller
{
    public function __invoke(Request $request, RoleHome $roles): RedirectResponse
    {
        return redirect()->to($roles->url($request->user()));
    }
}
