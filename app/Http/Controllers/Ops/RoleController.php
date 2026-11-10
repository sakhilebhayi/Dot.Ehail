<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Team and roles: who is an operator, with which roles; the role and permission matrix; grant and remove (audited). */
class RoleController extends Controller
{
    public function index(OpsAccess $access): View
    {
        $operators = User::query()->where('is_platform_operator', true)->orderBy('name')->get(['id', 'name', 'email']);

        return view('ops.admin.roles', [
            'operators' => $operators->map(fn (User $u) => ['user' => $u, 'roles' => $access->roles($u), 'legacy' => $access->hasLegacyFullAccess($u)]),
            'roles' => OpsRole::cases(),
            'permissions' => OpsPermission::DESCRIPTIONS,
        ]);
    }

    public function store(Request $request, RoleAdministration $admin): RedirectResponse
    {
        $v = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(OpsRole::class)],
        ], ['email.exists' => 'Nobody with that email has signed in to Dot.Ehail yet.']);

        $user = User::query()->where('email', $v['email'])->firstOrFail();
        $admin->setRoles($request->user(), $user, $v['roles']);

        return redirect()->route('ops.roles')->with('status', $user->name.' now has operations access.');
    }

    public function update(Request $request, User $user, RoleAdministration $admin): RedirectResponse
    {
        $v = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::enum(OpsRole::class)]], ['roles.required' => 'Choose at least one role. To take away all access, remove operator access instead.']);
        abort_unless($user->isPlatformOperator(), 404);
        $admin->setRoles($request->user(), $user, $v['roles']);

        return redirect()->route('ops.roles')->with('status', 'Roles saved for '.$user->name.'.');
    }

    public function destroy(Request $request, User $user, RoleAdministration $admin): RedirectResponse
    {
        abort_unless($user->isPlatformOperator(), 404);
        $admin->removeOperator($request->user(), $user);

        return redirect()->route('ops.roles')->with('status', $user->name.' no longer has operations access.');
    }
}
