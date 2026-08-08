# Fleet/Operator Entity + Driver Application Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user can apply to drive (joining an existing fleet or starting their own), the fleet owner is notified for real, and can approve or reject the pending application from a real review screen — reject requires a reason.

**Architecture:** New `Fleet` model + table (the first-class Operator entity `wiki.md` defers). `driver_profiles` gains `fleet_id` + a real `rejected` terminal state + reviewer tracking. Two new plain controllers (`DriverApplicationController` for the driver side, `FleetController` for the operator side), matching this app's existing controller-per-concern convention — this app has exactly one Livewire component and everything else is Blade + Controllers.

**Tech Stack:** Laravel (this repo's existing conventions), PHPUnit, `Notification::fake()`.

## Global Constraints

- `DriverProfile` carries a `HasUserScope` global scope (`app/Models/Concerns/HasUserScope.php`) that filters every query to `where('user_id', auth()->id())`. **Any query for a driver profile that isn't the current user's own — which is every query `FleetController` makes — must use `DriverProfile::withoutGlobalScope('user')->...`**, exactly matching the existing pattern already in `routes/web.php`'s dashboard closure. Forgetting this makes the review screen silently show nothing for every fleet owner.
- `Fleet` is deliberately **not** given `HasUserScope` — any authenticated user must be able to see the list of fleets to apply to, the same reasoning that already keeps `Ride`/`RideRating` unscoped (see `HasUserScope`'s own docblock).
- Every driver profile belongs to exactly one fleet — `driver_profiles.fleet_id` is `NOT NULL`.
- Rejecting an application requires a non-empty reason — a Laravel validation failure, never silently accepted.
- `driver_profiles.user_id` stays unique — a user can only ever have one driver application/profile (already DB-enforced, untouched).
- `HasUserScope`'s own docblock and `DriverApplicationSubmittedNotification`'s own docblock both currently say the Fleet/Operator entity doesn't exist — both become inaccurate the moment this plan ships and must be updated, not left stale.
- This repo was on a stale `fix/legal-page-links` branch with pre-existing uncommitted changes (`application-mark.blade.php`, `mark-light.png`, `mark.png`) when this plan was written; those were stashed and the work moved to `main`. Never touch those files or pop that stash — they're unrelated, pre-existing work.

---

### Task 1: `Fleet` model + migration + factory

**Files:**
- Create: `database/migrations/2026_08_09_000001_create_fleets_table.php`
- Create: `app/Models/Fleet.php`
- Create: `database/factories/FleetFactory.php`
- Test: `tests/Unit/FleetTest.php`

**Interfaces:**
- Produces: `Fleet` model with `owner(): BelongsTo` (User), `driverProfiles(): HasMany` (DriverProfile, unscoped by nature of being the "many" side declared on `Fleet`, not queried through `DriverProfile` directly — Task 4 still must use `withoutGlobalScope` when querying `DriverProfile` directly). `FleetFactory` with `owner_user_id` defaulting to a new `User::factory()`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_create_fleets_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleets');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/FleetTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fleet_belongs_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);

        $this->assertTrue($fleet->owner->is($owner));
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/FleetTest.php`
Expected: FAIL — `Class "App\Models\Fleet" not found`.

- [ ] **Step 5: Write `app/Models/Fleet.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fleet extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'owner_user_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function driverProfiles(): HasMany
    {
        return $this->hasMany(DriverProfile::class);
    }
}
```

- [ ] **Step 6: Write `database/factories/FleetFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetFactory extends Factory
{
    protected $model = Fleet::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'owner_user_id' => User::factory(),
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Unit/FleetTest.php`
Expected: PASS (1 test)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_09_000001_create_fleets_table.php app/Models/Fleet.php \
  database/factories/FleetFactory.php tests/Unit/FleetTest.php
git commit -m "feat: Fleet model -- the Operator entity wiki.md deferred

New table, model, factory. Deliberately not given HasUserScope: any
authenticated user must be able to see the fleet list to apply to."
```

---

### Task 2: `driver_profiles` gains `fleet_id`, `rejected`, reviewer tracking

**Files:**
- Create: `database/migrations/2026_08_09_000002_add_fleet_and_review_fields_to_driver_profiles_table.php`
- Modify: `app/Models/DriverProfile.php`
- Modify: `app/Models/Concerns/HasUserScope.php` (docblock only)
- Create: `database/factories/DriverProfileFactory.php`
- Create: `database/factories/VehicleFactory.php`
- Test: `tests/Unit/DriverProfileTest.php`

**Interfaces:**
- Consumes: `Fleet` (Task 1).
- Produces: `DriverProfile::fleet(): BelongsTo`, `$fillable` including `fleet_id`/`rejected_reason`/`reviewed_by`/`reviewed_at`, `status` enum now including `'rejected'`. `DriverProfileFactory`/`VehicleFactory` for Task 3/4's tests.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000002_add_fleet_and_review_fields_to_driver_profiles_table.php`. Modifying an enum column in this database driver requires dropping and recreating it (check `config/database.php`'s default connection first — if it's `sqlite`, as this app's tests use, SQLite has no native enum type at all; Laravel's `enum()` blueprint method emulates it with a `CHECK` constraint, and changing it needs the same drop-and-recreate approach used elsewhere in this app, e.g. `2026_08_08_181200_make_knowledge_pack_strategy_fields_nullable.php`-style separate `Schema::table()` calls in ChartSense — here, do it as raw column replacement):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable()->after('user_id')->constrained('fleets')->cascadeOnDelete();
            $table->text('rejected_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        // fleet_id starts nullable so this migration can run against any
        // pre-existing rows without failing; there are none in this
        // codebase today (nothing creates a DriverProfile yet -- see this
        // plan's spec), so backfilling isn't needed, but the column is
        // tightened to NOT NULL immediately after for any future row.
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('fleet_id')->nullable(false)->change();
        });

        DB::statement("ALTER TABLE driver_profiles DROP CONSTRAINT IF EXISTS driver_profiles_status_check");
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
        DB::statement("UPDATE driver_profiles SET status = 'pending' WHERE status NOT IN ('pending', 'approved', 'suspended', 'rejected')");
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['rejected_reason', 'reviewed_at']);
        });
    }
};
```

Add `use Illuminate\Support\Facades\DB;` to this file's imports.

Note: converting `status` from a native `enum` column to a plain `string` (rather than trying to add `'rejected'` to the existing enum definition) is deliberate — Laravel's schema builder cannot append a value to an existing enum column portably across drivers, and this app's own `DB::statement` in a prior migration (`ALTER TABLE ... DROP CONSTRAINT` pattern above) shows raw-statement drops are already an accepted approach here. The application-level validation (Task 4's approve/reject logic only ever writing `'pending'`, `'approved'`, `'suspended'`, or `'rejected'`) is what actually constrains the values going forward.

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration runs with no errors.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/DriverProfileTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_profile_belongs_to_a_fleet(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-300',
            'id_number' => 'ID-300',
            'status' => 'pending',
        ]);

        $this->assertTrue($profile->fleet->is($fleet));
    }

    public function test_status_can_be_rejected_with_a_reason_and_reviewer(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-301',
            'id_number' => 'ID-301',
            'status' => 'pending',
        ]);

        $profile->update([
            'status' => 'rejected',
            'rejected_reason' => 'Expired license.',
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $profile->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Expired license.', $fresh->rejected_reason);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/DriverProfileTest.php`
Expected: FAIL — `fleet_id`/`rejected_reason`/`reviewed_by`/`reviewed_at` are not in `DriverProfile::$fillable` yet, so `DriverProfile::create([...])` silently drops them (mass-assignment) and `$profile->fleet` errors (no `fleet()` relation method exists).

- [ ] **Step 5: Update `app/Models/DriverProfile.php`**

Replace the full contents:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasFactory, HasUserScope;

    protected $fillable = [
        'user_id',
        'fleet_id',
        'license_number',
        'id_number',
        'status',
        'is_online',
        'rating',
        'total_rides',
        'rejected_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'rating' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function activeVehicle(): ?Vehicle
    {
        return $this->vehicles()->where('is_active', true)->first();
    }
}
```

- [ ] **Step 6: Update `HasUserScope`'s docblock**

In `app/Models/Concerns/HasUserScope.php`, the docblock's opening sentence currently reads "Dot.Ehail has no fleet/operator entity yet (see wiki.md §3/§7/§8)". Replace that sentence with:

```
Dot.Ehail's Fleet/Operator entity (app/Models/Fleet.php) exists now, but it
is deliberately not given this scope -- any authenticated user must be able
to see the fleet list to apply to. DriverProfile keeps this scope: the
only genuinely single-owner tenancy relationship on it is still the
driver's own row (driver_profiles.user_id); FleetController explicitly
calls withoutGlobalScope('user') wherever a fleet owner needs to see a
driver profile that isn't their own.
```

Leave the rest of the docblock (the `Ride`/`RideRating` exception explanation, the mass-assignment note) unchanged.

- [ ] **Step 7: Write `database/factories/DriverProfileFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fleet_id' => Fleet::factory(),
            'license_number' => 'LIC-'.$this->faker->unique()->numberBetween(10000, 99999),
            'id_number' => 'ID-'.$this->faker->unique()->numberBetween(10000, 99999),
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 8: Write `database/factories/VehicleFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'driver_profile_id' => DriverProfile::factory(),
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2022,
            'color' => 'White',
            'plate_number' => 'CA '.$this->faker->unique()->numberBetween(100000, 999999),
            'type' => 'standard',
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Unit/DriverProfileTest.php`
Expected: PASS (2 tests)

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_09_000002_add_fleet_and_review_fields_to_driver_profiles_table.php \
  app/Models/DriverProfile.php app/Models/Concerns/HasUserScope.php \
  database/factories/DriverProfileFactory.php database/factories/VehicleFactory.php \
  tests/Unit/DriverProfileTest.php
git commit -m "feat: driver_profiles gains fleet_id, rejected status, reviewer tracking

status converts from a native enum to a plain string (portable append of
a new value; app-level code is what constrains it now). fleet_id is
NOT NULL -- every driver profile belongs to exactly one fleet.
HasUserScope's docblock updated to stop saying Fleet doesn't exist."
```

---

### Task 3: Driver application submission

**Files:**
- Create: `app/Http/Controllers/Ehail/DriverApplicationController.php`
- Create: `resources/views/drivers/apply.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Notifications/DriverApplicationSubmittedNotification.php` (docblock only)
- Test: `tests/Feature/Ehail/DriverApplicationTest.php`

**Interfaces:**
- Consumes: `Fleet` (Task 1), `DriverProfile`/`Vehicle` (Task 2).
- Produces: routes `drive.apply` (GET), `drive.apply.store` (POST) — Task 4's tests don't consume these directly, but the whole feature is exercised together in Task 5's regression pass.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Ehail/DriverApplicationTest.php`:

```php
<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DriverApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'license_number' => 'LIC-500',
            'id_number' => 'ID-500',
            'new_fleet_name' => 'My Own Fleet',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year' => 2022,
            'vehicle_color' => 'White',
            'vehicle_plate_number' => 'CA 123456',
            'vehicle_type' => 'standard',
        ], $overrides);
    }

    public function test_applying_with_a_new_fleet_name_creates_the_fleet_and_a_pending_profile(): void
    {
        Notification::fake();
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, Fleet::count());
        $fleet = Fleet::first();
        $this->assertSame($applicant->id, $fleet->owner_user_id);
        $this->assertSame('My Own Fleet', $fleet->name);

        $profile = DriverProfile::withoutGlobalScope('user')->first();
        $this->assertSame('pending', $profile->status);
        $this->assertSame($fleet->id, $profile->fleet_id);
        $this->assertSame($applicant->id, $profile->user_id);
        $this->assertSame(1, $profile->vehicles()->count());
    }

    public function test_applying_to_an_existing_fleet_does_not_create_a_second_fleet(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                'new_fleet_name' => null,
            ]))
            ->assertRedirect();

        $this->assertSame(1, Fleet::count());
        $profile = DriverProfile::withoutGlobalScope('user')->first();
        $this->assertSame($fleet->id, $profile->fleet_id);
    }

    public function test_submitting_with_both_fleet_options_fails_validation(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                // new_fleet_name also present from validPayload()
            ]))
            ->assertSessionHasErrors();

        $this->assertSame(0, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_submitting_with_neither_fleet_option_fails_validation(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload(['new_fleet_name' => null]))
            ->assertSessionHasErrors();

        $this->assertSame(0, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_the_fleet_owner_receives_a_real_notification(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post(route('drive.apply.store'), $this->validPayload([
            'existing_fleet_id' => $fleet->id,
            'new_fleet_name' => null,
        ]));

        Notification::assertSentTo($owner, DriverApplicationSubmittedNotification::class);
    }

    public function test_a_user_who_already_has_a_driver_profile_cannot_submit_a_second_one(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Existing Fleet', 'owner_user_id' => $owner->id]);
        $applicant = User::factory()->create();
        DriverProfile::create([
            'user_id' => $applicant->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-EXISTING',
            'id_number' => 'ID-EXISTING',
            'status' => 'pending',
        ]);

        $this->actingAs($applicant)
            ->post(route('drive.apply.store'), $this->validPayload([
                'existing_fleet_id' => $fleet->id,
                'new_fleet_name' => null,
                'license_number' => 'LIC-SECOND',
                'id_number' => 'ID-SECOND',
                'vehicle_plate_number' => 'CA 999999',
            ]))
            ->assertSessionHasErrors();

        $this->assertSame(1, DriverProfile::withoutGlobalScope('user')->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Ehail/DriverApplicationTest.php`
Expected: FAIL — the `drive.apply.store` route doesn't exist yet (404s cause `assertRedirect()`/`assertSessionHasErrors()` to fail).

- [ ] **Step 3: Write `app/Http/Controllers/Ehail/DriverApplicationController.php`**

```php
<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DriverApplicationController extends Controller
{
    public function create(): View
    {
        $fleets = Fleet::orderBy('name')->get();

        return view('drivers.apply', ['fleets' => $fleets]);
    }

    public function store(Request $request): RedirectResponse
    {
        $existingProfile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($existingProfile) {
            return back()->withErrors(['license_number' => 'You already have a driver application on file.']);
        }

        $validated = $request->validate([
            'license_number' => 'required|string|unique:driver_profiles,license_number',
            'id_number' => 'required|string|unique:driver_profiles,id_number',
            'existing_fleet_id' => 'nullable|required_without:new_fleet_name|prohibited_with:new_fleet_name|exists:fleets,id',
            'new_fleet_name' => 'nullable|string|required_without:existing_fleet_id|prohibited_with:existing_fleet_id',
            'vehicle_make' => 'required|string',
            'vehicle_model' => 'required|string',
            'vehicle_year' => 'required|integer',
            'vehicle_color' => 'required|string',
            'vehicle_plate_number' => 'required|string|unique:vehicles,plate_number',
            'vehicle_type' => 'required|string|in:economy,standard,premium,suv',
        ]);

        if (! empty($validated['new_fleet_name'])) {
            $fleet = Fleet::create([
                'name' => $validated['new_fleet_name'],
                'owner_user_id' => $request->user()->id,
            ]);
        } else {
            $fleet = Fleet::findOrFail($validated['existing_fleet_id']);
        }

        $profile = DriverProfile::create([
            'user_id' => $request->user()->id,
            'fleet_id' => $fleet->id,
            'license_number' => $validated['license_number'],
            'id_number' => $validated['id_number'],
            'status' => 'pending',
        ]);

        Vehicle::create([
            'driver_profile_id' => $profile->id,
            'make' => $validated['vehicle_make'],
            'model' => $validated['vehicle_model'],
            'year' => $validated['vehicle_year'],
            'color' => $validated['vehicle_color'],
            'plate_number' => $validated['vehicle_plate_number'],
            'type' => $validated['vehicle_type'],
        ]);

        $fleet->owner->notify(new DriverApplicationSubmittedNotification($profile));

        return redirect()->route('drivers.show', $profile)
            ->with('status', 'Application submitted. The fleet owner will review it.');
    }
}
```

- [ ] **Step 4: Add the apply-form view**

Create `resources/views/drivers/apply.blade.php`, matching the existing dark-theme inline-style convention (`drivers/show.blade.php`):

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 1.5rem;letter-spacing:-0.01em;">
        Apply to Drive
    </h1>

    @if ($errors->any())
        <div class="dot-card" style="padding:1rem;margin-bottom:1.5rem;border:1px solid rgba(239,68,68,0.3);">
            @foreach ($errors->all() as $error)
                <p style="font-size:0.8rem;color:#f87171;margin:0.25rem 0;">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('drive.apply.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">License Number</label>
            <input type="text" name="license_number" value="{{ old('license_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">ID Number</label>
            <input type="text" name="id_number" value="{{ old('id_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Join an existing fleet</label>
            <select name="existing_fleet_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— Select a fleet —</option>
                @foreach ($fleets as $fleet)
                    <option value="{{ $fleet->id }}" @selected(old('existing_fleet_id') == $fleet->id)>{{ $fleet->name }}</option>
                @endforeach
            </select>
        </div>

        <p style="font-size:0.75rem;color:#71717a;text-align:center;">— or —</p>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Start my own fleet</label>
            <input type="text" name="new_fleet_name" value="{{ old('new_fleet_name') }}" placeholder="Fleet name" style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <hr style="border-color:rgba(255,255,255,0.08);">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Make</label>
                <input type="text" name="vehicle_make" value="{{ old('vehicle_make') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Model</label>
                <input type="text" name="vehicle_model" value="{{ old('vehicle_model') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Year</label>
                <input type="number" name="vehicle_year" value="{{ old('vehicle_year') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Color</label>
                <input type="text" name="vehicle_color" value="{{ old('vehicle_color') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Plate Number</label>
                <input type="text" name="vehicle_plate_number" value="{{ old('vehicle_plate_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Type</label>
                <select name="vehicle_type" required style="width:100%;padding:0.5rem;border-radius:6px;">
                    <option value="economy">Economy</option>
                    <option value="standard" selected>Standard</option>
                    <option value="premium">Premium</option>
                    <option value="suv">SUV</option>
                </select>
            </div>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:#f59e0b;color:#18181b;font-weight:700;border:none;cursor:pointer;">
            Submit Application
        </button>
    </form>
</div>
</x-app-layout>
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\Ehail\DriverApplicationController;` to the imports, and add two lines inside the existing `auth:sanctum` middleware group, right after the `Route::get('/drivers/{driverProfile}', ...)` line:

```php
    Route::get('/drive/apply', [DriverApplicationController::class, 'create'])->name('drive.apply');
    Route::post('/drive/apply', [DriverApplicationController::class, 'store'])->name('drive.apply.store');
```

- [ ] **Step 6: Update `DriverApplicationSubmittedNotification`'s docblock**

In `app/Notifications/DriverApplicationSubmittedNotification.php`, replace the docblock's second and third sentences ("Not yet wired to any automatic trigger — dispatch manually via `$operator->notify(new DriverApplicationSubmittedNotification($driverProfile))` until driver onboarding has real observer/event wiring (see wiki.md §8).") with:

```
Fired automatically by DriverApplicationController::store() to the
applicant's fleet owner the moment a driver application is submitted.
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Ehail/DriverApplicationTest.php`
Expected: PASS (6 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Ehail/DriverApplicationController.php resources/views/drivers/apply.blade.php \
  routes/web.php app/Notifications/DriverApplicationSubmittedNotification.php \
  tests/Feature/Ehail/DriverApplicationTest.php
git commit -m "feat: driver application submission (join a fleet or start one)

The submission flow this platform never had. Creates a pending
DriverProfile + Vehicle, fires DriverApplicationSubmittedNotification to
the fleet owner for real -- the trigger its own docblock said didn't
exist yet."
```

---

### Task 4: Fleet review screen — approve/reject

**Files:**
- Create: `app/Policies/FleetPolicy.php`
- Modify: `app/Policies/DriverProfilePolicy.php`
- Create: `app/Http/Controllers/Ehail/FleetController.php`
- Create: `resources/views/fleets/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Ehail/FleetDriverReviewTest.php`

**Interfaces:**
- Consumes: `Fleet`/`DriverProfile` (Tasks 1-2).
- Produces: routes `fleets.show`, `fleets.drivers.approve`, `fleets.drivers.reject`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Ehail/FleetDriverReviewTest.php`:

```php
<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDriverReviewTest extends TestCase
{
    use RefreshDatabase;

    private function pendingApplication(Fleet $fleet): DriverProfile
    {
        $applicant = User::factory()->create();
        $profile = DriverProfile::create([
            'user_id' => $applicant->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-'.$applicant->id,
            'id_number' => 'ID-'.$applicant->id,
            'status' => 'pending',
        ]);
        Vehicle::create([
            'driver_profile_id' => $profile->id,
            'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2022, 'color' => 'White',
            'plate_number' => 'CA '.$applicant->id, 'type' => 'standard',
        ]);

        return $profile;
    }

    public function test_fleet_owner_can_view_pending_applications(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->get(route('fleets.show', $fleet))
            ->assertOk()
            ->assertSee($profile->license_number);
    }

    public function test_fleet_owner_can_approve_a_pending_driver(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertRedirect();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_reject_without_a_reason_is_blocked(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), [])
            ->assertSessionHasErrors('reason');

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_reject_with_a_reason_marks_the_driver_rejected(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.reject', [$fleet, $profile]), ['reason' => 'Expired license.'])
            ->assertRedirect();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Expired license.', $fresh->rejected_reason);
    }

    public function test_a_different_fleets_owner_cannot_view_or_approve(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $stranger = User::factory()->create();
        Fleet::create(['name' => 'Other Fleet', 'owner_user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->get(route('fleets.show', $fleet))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertForbidden();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_approving_an_already_decided_driver_is_refused(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);
        $profile->update(['status' => 'approved']);

        $this->actingAs($owner)
            ->post(route('fleets.drivers.approve', [$fleet, $profile]))
            ->assertSessionHasErrors();

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame('approved', $fresh->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Ehail/FleetDriverReviewTest.php`
Expected: FAIL — `fleets.show`/`fleets.drivers.approve`/`fleets.drivers.reject` routes don't exist yet.

- [ ] **Step 3: Write `app/Policies/FleetPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\Fleet;
use App\Models\User;

class FleetPolicy
{
    /**
     * Only the fleet's owner may view it (its pending/reviewed driver
     * applications) or act on its drivers.
     */
    public function view(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id;
    }
}
```

- [ ] **Step 4: Extend `DriverProfilePolicy::view`**

In `app/Policies/DriverProfilePolicy.php`, replace the `view()` method:

```php
    /**
     * Determine whether the user can view the given driver profile and its ride history.
     *
     * Extended (this plan) to also allow the driver's own fleet owner --
     * they now have a real reason to (reviewing/having reviewed the
     * application). This is additive to the 2026-08-01 fix's intent: a
     * stranger still cannot view an unrelated driver's profile.
     */
    public function view(User $user, DriverProfile $driverProfile): bool
    {
        if ($user->id === $driverProfile->user_id) {
            return true;
        }

        return $driverProfile->fleet && $user->id === $driverProfile->fleet->owner_user_id;
    }
```

- [ ] **Step 5: Write `app/Http/Controllers/Ehail/FleetController.php`**

```php
<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FleetController extends Controller
{
    /**
     * DriverProfile carries HasUserScope (app/Models/Concerns/HasUserScope.php),
     * which filters every query to the authenticated user's own row. A fleet
     * owner reviewing someone else's application must bypass that scope
     * explicitly -- exactly the pattern routes/web.php's dashboard closure
     * already uses.
     */
    public function show(Fleet $fleet): View
    {
        Gate::authorize('view', $fleet);

        $driverProfiles = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->with(['user', 'vehicles'])
            ->latest()
            ->get();

        return view('fleets.show', [
            'fleet' => $fleet,
            'pending' => $driverProfiles->where('status', 'pending'),
            'reviewed' => $driverProfiles->whereIn('status', ['approved', 'suspended', 'rejected']),
        ]);
    }

    public function approveDriver(Fleet $fleet, DriverProfile $rawDriverProfile, Request $request): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        $driverProfile = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->findOrFail($rawDriverProfile->id);

        if ($driverProfile->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending application can be approved.']);
        }

        $driverProfile->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Driver approved.');
    }

    public function rejectDriver(Fleet $fleet, DriverProfile $rawDriverProfile, Request $request): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        $driverProfile = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->findOrFail($rawDriverProfile->id);

        if ($driverProfile->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending application can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $driverProfile->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Driver rejected.');
    }
}
```

Note the `$rawDriverProfile` parameter name: Laravel's implicit route-model binding for `{driverProfile}` resolves through `DriverProfile`'s default query, which **still applies `HasUserScope`** and will 404 for any driver profile that isn't the current user's own -- exactly the failure mode this plan's Global Constraints section warns about. Naming it `$rawDriverProfile` and immediately re-resolving via `withoutGlobalScope('user')` (rather than trusting the bound `$driverProfile` directly) is the fix; do not skip the re-resolve step or "simplify" it away.

- [ ] **Step 6: Add the fleet review view**

Create `resources/views/fleets/show.blade.php`:

```blade
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">
        {{ $fleet->name }}
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 2rem;">Driver applications</p>

    @if (session('status'))
        <div class="dot-card" style="padding:0.75rem 1rem;margin-bottom:1.5rem;border:1px solid rgba(34,197,94,0.3);">
            <p style="font-size:0.8rem;color:#4ade80;margin:0;">{{ session('status') }}</p>
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $profile)
        <div class="dot-card" style="padding:1.25rem;margin-bottom:1rem;">
            <p style="font-size:0.95rem;font-weight:600;color:#f4f4f5;margin:0 0 0.25rem;">{{ $profile->user?->name }}</p>
            <p style="font-size:0.78rem;color:#71717a;margin:0 0 0.75rem;">License {{ $profile->license_number }} · ID {{ $profile->id_number }}</p>
            @foreach ($profile->vehicles as $vehicle)
                <p style="font-size:0.78rem;color:#a1a1aa;margin:0 0 0.75rem;">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} ({{ $vehicle->color }}) — {{ $vehicle->plate_number }}</p>
            @endforeach

            <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                <form method="POST" action="{{ route('fleets.drivers.approve', [$fleet, $profile]) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#22c55e;color:#052e13;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve</button>
                </form>
                <form method="POST" action="{{ route('fleets.drivers.reject', [$fleet, $profile]) }}" style="display:flex;gap:0.4rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for rejecting" required style="padding:0.4rem;border-radius:6px;font-size:0.78rem;">
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#ef4444;color:#3f0d0d;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Reject</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No pending applications.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:2rem 0 1rem;">Reviewed</h2>
    @forelse ($reviewed as $profile)
        <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <p style="font-size:0.9rem;color:#f4f4f5;margin:0;">{{ $profile->user?->name }}</p>
                <p style="font-size:0.75rem;color:#71717a;margin:0;">{{ $profile->license_number }}</p>
            </div>
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $profile->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : ($profile->status === 'rejected' ? 'background:rgba(239,68,68,0.1);color:#f87171;' : 'background:rgba(245,158,11,0.1);color:#f59e0b;') }}">
                {{ ucfirst($profile->status) }}
            </span>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No reviewed applications yet.</p>
    @endforelse
</div>
</x-app-layout>
```

- [ ] **Step 7: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\Ehail\FleetController;` and `use App\Models\Fleet;` to the imports (the latter may already be needed elsewhere; add only if not already imported), and add three lines inside the existing `auth:sanctum` middleware group, after the two `drive.apply` lines from Task 3:

```php
    Route::get('/fleets/{fleet}', [FleetController::class, 'show'])->name('fleets.show');
    Route::post('/fleets/{fleet}/drivers/{driverProfile}/approve', [FleetController::class, 'approveDriver'])->name('fleets.drivers.approve');
    Route::post('/fleets/{fleet}/drivers/{driverProfile}/reject', [FleetController::class, 'rejectDriver'])->name('fleets.drivers.reject');
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Ehail/FleetDriverReviewTest.php`
Expected: PASS (6 tests)

- [ ] **Step 9: Commit**

```bash
git add app/Policies/FleetPolicy.php app/Policies/DriverProfilePolicy.php \
  app/Http/Controllers/Ehail/FleetController.php resources/views/fleets/show.blade.php \
  routes/web.php tests/Feature/Ehail/FleetDriverReviewTest.php
git commit -m "feat: fleet owner review screen -- approve/reject driver applications

Only the fleet owner (FleetPolicy::view) can see or act on their fleet's
applications. Rejecting requires a non-empty reason. Approve/reject both
explicitly bypass DriverProfile's HasUserScope global scope to find the
target row -- implicit route-model binding alone would 404 for every
driver profile that isn't the reviewer's own."
```

---

### Task 5: Full regression + manual verification

**Files:** none new — verification only.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: 0 failures across the whole suite (confirms Tasks 1-4's migrations and the `HasUserScope`/`DriverProfilePolicy` changes didn't break `DriverProfileViewTest`'s existing scope/policy regression tests).

- [ ] **Step 2: Manual end-to-end verification**

```bash
php artisan tinker --execute '
$applicant = \App\Models\User::factory()->create(["name" => "Manual Test Applicant"]);
$owner = \App\Models\User::factory()->create(["name" => "Manual Test Owner"]);
$fleet = \App\Models\Fleet::create(["name" => "Manual Test Fleet", "owner_user_id" => $owner->id]);
echo "applicant_id={$applicant->id} owner_id={$owner->id} fleet_id={$fleet->id}\n";
'
```

Then, as the applicant (simulate the controller flow directly to avoid needing a running HTTP server):

```bash
php artisan tinker --execute '
auth()->loginUsingId(<applicant_id>);
$request = \Illuminate\Http\Request::create("/drive/apply", "POST", [
    "license_number" => "LIC-MANUAL", "id_number" => "ID-MANUAL",
    "existing_fleet_id" => <fleet_id>,
    "vehicle_make" => "Toyota", "vehicle_model" => "Corolla", "vehicle_year" => 2022,
    "vehicle_color" => "White", "vehicle_plate_number" => "CA MANUAL1", "vehicle_type" => "standard",
]);
$request->setUserResolver(fn () => auth()->user());
app(\App\Http\Controllers\Ehail\DriverApplicationController::class)->store($request);
$profile = \App\Models\DriverProfile::withoutGlobalScope("user")->where("license_number", "LIC-MANUAL")->first();
echo "profile status after submission: {$profile->status}\n";
'
```

Then, as the fleet owner, approve it:

```bash
php artisan tinker --execute '
$profile = \App\Models\DriverProfile::withoutGlobalScope("user")->where("license_number", "LIC-MANUAL")->first();
$fleet = \App\Models\Fleet::find(<fleet_id>);
auth()->loginUsingId(<owner_id>);
$request = \Illuminate\Http\Request::create("/", "POST");
$request->setUserResolver(fn () => auth()->user());
app(\App\Http\Controllers\Ehail\FleetController::class)->approveDriver($fleet, $profile, $request);
echo "profile status after approval: {$profile->fresh()->status}\n";
'
```

Expected: first tinker call prints `pending`, second prints `approved`. Confirms the real Eloquent lifecycle end-to-end, not just in-memory test doubles.

- [ ] **Step 3: Clean up manual verification fixtures**

```bash
php artisan tinker --execute '
$profile = \App\Models\DriverProfile::withoutGlobalScope("user")->where("license_number", "LIC-MANUAL")->first();
if ($profile) { $profile->vehicles()->delete(); $profile->delete(); }
\App\Models\Fleet::where("name", "Manual Test Fleet")->delete();
\App\Models\User::where("name", "like", "Manual Test%")->delete();
echo "cleaned up manual verification fixtures\n";
'
```

- [ ] **Step 4: Report completion**

No commit for this task — it's verification only. If Step 1 finds any failures, stop and fix them (return to the relevant earlier task) before considering this plan complete.

## Self-Review Notes

- **Spec coverage:** Task 1 covers spec §1. Task 2 covers spec §2. Task 3 covers spec §3. Task 4 covers spec §4 and §5. Task 5 covers the spec's implicit "this all actually works together" requirement.
- **Placeholder scan:** none — every step has literal file content, including full controller/view/policy/migration contents.
- **Type consistency:** `Fleet::driverProfiles()`, `DriverProfile::fleet()`/`reviewer()`, `FleetController::show/approveDriver/rejectDriver` signatures are used identically across every task that references them.
- **The `HasUserScope` trap, addressed three times on purpose:** Global Constraints states it once; Task 4 Step 5's `FleetController` code and its explanatory note address it in the implementation itself (the `$rawDriverProfile` naming and re-resolve pattern); Task 4's own tests (`test_fleet_owner_can_approve_a_pending_driver` etc.) assert against `DriverProfile::withoutGlobalScope('user')->find(...)`, not a plain `find()`, so a regression that reintroduces the scoping bug would show up as a test using the wrong assertion path, not a silently-green false pass. This repetition is deliberate, not redundant — it was the single most likely way to ship a broken review screen undetected.
- **Uncommitted pre-existing changes:** this repo was moved from `fix/legal-page-links` to `main` and its pre-existing uncommitted changes stashed (see this spec's own note) before this plan was written; every `git add` in this plan lists files explicitly.
