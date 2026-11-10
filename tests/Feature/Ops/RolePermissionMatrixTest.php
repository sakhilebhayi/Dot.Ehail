<?php

namespace Tests\Feature\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\RoutePermissions;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * The role and permission matrix, written here as the specification (route => roles allowed) independently of
 * App\Domain\Ops\Access\RoutePermissions, and checked against the real `operator` middleware for EVERY route behind
 * it (this centre's pages and the dispatch, money, pricing, safety, support and legacy operator APIs), for each of
 * the eight roles, a legacy operator (no roles) and a non-operator.
 */
class RolePermissionMatrixTest extends TestCase
{
    use OpsFixtures;
    use RefreshDatabase;

    private const ALL = ['ops_agent', 'dispatcher', 'verification_reviewer', 'safety_officer', 'support_agent', 'finance', 'pricing_manager'];

    /**
     * Specification: route-name pattern => roles (besides admin) allowed. First match wins, most specific first.
     *
     * @var array<string, list<string>>
     */
    private const SPEC = [
        'ops.home' => self::ALL,
        'ops.live*' => ['ops_agent', 'dispatcher', 'safety_officer'],
        'ops.rides*' => ['ops_agent', 'dispatcher', 'safety_officer', 'support_agent', 'finance'],
        'ops.drivers.suspend' => ['safety_officer'],
        'ops.drivers.lift' => ['safety_officer'],
        'ops.drivers.appeal-decide' => ['safety_officer'],
        'ops.drivers*' => ['ops_agent', 'dispatcher', 'verification_reviewer', 'safety_officer', 'support_agent', 'finance'],
        'ops.verification*' => ['verification_reviewer'],
        'ops.safety.access' => ['safety_officer'],
        'ops.safety*' => ['ops_agent', 'safety_officer', 'support_agent'],
        'ops.support*' => ['ops_agent', 'safety_officer', 'support_agent'],
        'ops.pricing*' => ['pricing_manager'],
        'ops.zones' => ['pricing_manager'],
        'ops.zones.*' => ['pricing_manager'],
        'ops.finance*' => ['finance'],
        'ops.insights*' => ['ops_agent', 'dispatcher', 'finance', 'pricing_manager'],
        'ops.audit*' => ['safety_officer'],
        'ops.roles*' => [],
        'ops.fleets*' => ['verification_reviewer'],

        'ehail.dispatch.ops.rides.index' => ['ops_agent', 'dispatcher', 'safety_officer', 'support_agent', 'finance'],
        'ehail.dispatch.ops.rides.timeline' => ['ops_agent', 'dispatcher', 'safety_officer', 'support_agent', 'finance'],
        'ehail.dispatch.ops.*' => ['dispatcher'],

        'money.ops.refund' => ['finance'],
        'money.ops.*' => ['finance'],

        'pricing.ops.rides.fare-adjustments.store' => ['support_agent', 'finance'],
        'pricing.ops.*' => ['pricing_manager'],

        'safety.ops.verification.*' => ['verification_reviewer'],
        'safety.ops.incidents.queue' => ['ops_agent', 'safety_officer', 'support_agent'],
        'safety.ops.incidents.show' => ['ops_agent', 'safety_officer', 'support_agent'],
        'safety.ops.reports.index' => ['ops_agent', 'safety_officer', 'support_agent'],
        'safety.ops.comments.index' => ['ops_agent', 'safety_officer', 'support_agent'],
        'safety.ops.appeals.index' => ['ops_agent', 'safety_officer', 'support_agent'],
        'safety.ops.*' => ['safety_officer'],

        'support.ops.cases.queue' => ['ops_agent', 'safety_officer', 'support_agent'],
        'support.ops.cases.show' => ['ops_agent', 'safety_officer', 'support_agent'],
        'support.ops.*' => ['support_agent'],

        'operator.rides.index' => ['ops_agent', 'dispatcher', 'safety_officer', 'support_agent', 'finance'],
        'operator.rides.complete' => ['dispatcher'],
        'operator.rides.cancel' => ['dispatcher'],
        'operator.rides.waive' => ['finance'],
        'operator.payments.review' => ['finance'],
        'operator.fleets.*' => ['verification_reviewer'],
    ];

    /** @return list<RoutingRoute> */
    private function operatorRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => in_array('operator', $r->gatherMiddleware(), true)
            || in_array(EnsurePlatformOperator::class, $r->gatherMiddleware(), true)));
    }

    /** @return list<string> */
    private function specFor(string $name): array
    {
        foreach (self::SPEC as $pattern => $roles) {
            if ($pattern === $name || Str::is($pattern, $name)) {
                return $roles;
            }
        }

        $this->fail("Route {$name} has no row in the role specification.");
    }

    private function passes(?User $user, RoutingRoute $route): bool
    {
        $request = Request::create('/'.$route->uri(), $route->methods()[0]);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        try {
            app(EnsurePlatformOperator::class)->handle($request, fn () => response('ok'));

            return true;
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());

            return false;
        }
    }

    public function test_every_operator_route_is_mapped_to_a_permission(): void
    {
        $routes = $this->operatorRoutes();
        $this->assertGreaterThan(90, count($routes));

        foreach ($routes as $route) {
            $this->assertNotNull($route->getName(), 'An operator route has no name: '.$route->uri());
            $this->assertTrue(RoutePermissions::isMapped($route->getName()), 'Unmapped operator route: '.$route->getName());
        }
    }

    public function test_the_middleware_matches_the_specification_for_every_route_and_role(): void
    {
        $users = ['admin' => $this->operatorWith(['admin'])];
        foreach (self::ALL as $role) {
            $users[$role] = $this->operatorWith([$role]);
        }
        $legacy = $this->makeOperator();
        $stranger = User::factory()->create();

        $checked = 0;
        foreach ($this->operatorRoutes() as $route) {
            $allowed = $this->specFor($route->getName());

            $this->assertTrue($this->passes($users['admin'], $route), 'admin refused '.$route->getName());
            $this->assertTrue($this->passes($legacy, $route), 'legacy operator refused '.$route->getName());
            $this->assertFalse($this->passes($stranger, $route), 'non-operator let into '.$route->getName());

            foreach (self::ALL as $role) {
                $this->assertSame(in_array($role, $allowed, true), $this->passes($users[$role], $route), sprintf('%s on %s', $role, $route->getName()));
                $checked++;
            }
        }

        $this->assertGreaterThan(600, $checked);
    }

    public function test_combined_roles_add_up_and_a_removed_operator_loses_everything(): void
    {
        $user = $this->operatorWith(['dispatcher', 'finance']);
        $access = app(OpsAccess::class);

        $this->assertTrue($access->allowsRoute($user, 'ehail.dispatch.ops.rides.force-cancel'));
        $this->assertTrue($access->allowsRoute($user, 'money.ops.refund'));
        $this->assertFalse($access->allowsRoute($user, 'safety.ops.verification.approve'));

        $user->forceFill(['is_platform_operator' => false])->save();
        $access->forget($user);
        $this->assertFalse($access->allowsRoute($user->fresh(), 'ehail.dispatch.ops.rides.index'));
        $this->assertSame([], $access->permissions($user->fresh()));
    }
}
