<?php

namespace Tests\Feature\Ehail;

use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Notifications\DriverApplicationSubmittedNotification;
use App\Support\DriverIdentifiers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class DriverPiiTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private const LICENCE = 'ZA-LICENCE-557731';

    private const NATIONAL_ID = '8001015009087';

    private function profileWithIdentifiers(?Fleet $fleet = null, string $status = 'approved'): DriverProfile
    {
        return DriverProfile::factory()->create([
            'fleet_id' => ($fleet ?? Fleet::factory()->create())->id,
            'license_number' => self::LICENCE,
            'id_number' => self::NATIONAL_ID,
            'status' => $status,
        ]);
    }

    // ─── Encrypted at rest ───────────────────────────────────────────────

    public function test_identifiers_are_encrypted_in_the_database_and_plain_through_the_model(): void
    {
        $profile = $this->profileWithIdentifiers();

        $raw = DB::table('driver_profiles')->where('id', $profile->id)->first();
        $this->assertStringNotContainsString(self::LICENCE, $raw->license_number);
        $this->assertStringNotContainsString(self::NATIONAL_ID, $raw->id_number);
        $this->assertSame(self::LICENCE, Crypt::decryptString($raw->license_number));
        $this->assertSame(self::NATIONAL_ID, Crypt::decryptString($raw->id_number));

        $fresh = DriverProfile::withoutGlobalScope('user')->find($profile->id);
        $this->assertSame(self::LICENCE, $fresh->license_number);
        $this->assertSame(self::NATIONAL_ID, $fresh->id_number);
    }

    public function test_the_blind_index_columns_hold_no_plaintext(): void
    {
        $profile = $this->profileWithIdentifiers();

        $raw = DB::table('driver_profiles')->where('id', $profile->id)->first();
        $this->assertSame(DriverIdentifiers::blindIndex(self::LICENCE), $raw->license_number_hash);
        $this->assertSame(DriverIdentifiers::blindIndex(self::NATIONAL_ID), $raw->id_number_hash);
    }

    public function test_updating_an_identifier_refreshes_its_index_and_leaving_it_alone_does_not_change_it(): void
    {
        $profile = $this->profileWithIdentifiers();
        $before = DB::table('driver_profiles')->where('id', $profile->id)->value('license_number');

        $profile->update(['status' => 'suspended']);
        $this->assertSame($before, DB::table('driver_profiles')->where('id', $profile->id)->value('license_number'), 'an unrelated save must not re-encrypt');

        $profile->update(['license_number' => 'ZA-LICENCE-999000']);
        $this->assertSame(
            DriverIdentifiers::blindIndex('ZA-LICENCE-999000'),
            DB::table('driver_profiles')->where('id', $profile->id)->value('license_number_hash'),
        );
    }

    public function test_the_identifiers_never_appear_when_a_profile_is_serialised(): void
    {
        $json = $this->profileWithIdentifiers()->toJson();

        $this->assertStringNotContainsString(self::LICENCE, $json);
        $this->assertStringNotContainsString(self::NATIONAL_ID, $json);
        $this->assertStringNotContainsString('license_number', $json);
    }

    public function test_the_same_licence_cannot_be_registered_twice_even_with_different_case_or_spacing(): void
    {
        $this->profileWithIdentifiers();
        $other = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create();

        $this->actingAs($other)->post(route('drive.apply.store'), [
            'license_number' => ' za-licence-557731 ', 'id_number' => 'SOMETHING-ELSE-1',
            'existing_fleet_id' => $fleet->id,
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Etios', 'vehicle_year' => 2020, 'vehicle_color' => 'Red',
            'vehicle_plate_number' => 'CA 556 677', 'vehicle_type' => 'standard',
        ])->assertSessionHasErrors('license_number');

        $this->actingAs($other)->post(route('drive.apply.store'), [
            'license_number' => 'A-DIFFERENT-LICENCE', 'id_number' => self::NATIONAL_ID,
            'existing_fleet_id' => $fleet->id,
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Etios', 'vehicle_year' => 2020, 'vehicle_color' => 'Red',
            'vehicle_plate_number' => 'CA 556 677', 'vehicle_type' => 'standard',
        ])->assertSessionHasErrors('id_number');

        $this->assertSame(1, DriverProfile::withoutGlobalScope('user')->count());
    }

    public function test_the_database_refuses_a_duplicate_licence_through_the_index(): void
    {
        $this->profileWithIdentifiers();

        $this->expectException(QueryException::class);
        $this->profileWithIdentifiers();
    }

    public function test_an_application_stores_encrypted_identifiers(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create();

        $this->actingAs($user)->post(route('drive.apply.store'), [
            'license_number' => self::LICENCE, 'id_number' => self::NATIONAL_ID,
            'existing_fleet_id' => $fleet->id,
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Etios', 'vehicle_year' => 2020, 'vehicle_color' => 'Red',
            'vehicle_plate_number' => 'CA 556 677', 'vehicle_type' => 'standard',
        ])->assertSessionDoesntHaveErrors();

        $raw = DB::table('driver_profiles')->first();
        $this->assertStringNotContainsString(self::LICENCE, $raw->license_number);
        $this->assertSame(self::LICENCE, Crypt::decryptString($raw->license_number));
    }

    public function test_the_application_notification_no_longer_carries_the_licence_number(): void
    {
        $profile = $this->profileWithIdentifiers();
        $reviewer = User::factory()->create();

        $reviewer->notify(new DriverApplicationSubmittedNotification($profile));

        $stored = json_encode($reviewer->notifications()->first()->data);
        $this->assertStringNotContainsString(self::LICENCE, $stored);
        $this->assertStringContainsString('applied to drive', $stored);
    }

    // ─── Legacy plaintext rows ───────────────────────────────────────────

    private function legacyMigration(): object
    {
        return require database_path('migrations/2026_10_19_300100_encrypt_driver_profile_identifiers.php');
    }

    private function insertLegacyRow(string $licence, string $nationalId, ?string $status = 'approved'): int
    {
        return DB::table('driver_profiles')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'fleet_id' => Fleet::factory()->create()->id,
            'license_number' => $licence,
            'id_number' => $nationalId,
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_data_migration_encrypts_legacy_plaintext_rows(): void
    {
        $id = $this->insertLegacyRow('LEGACY-LIC-0001', '7001015009087');
        $this->assertSame('LEGACY-LIC-0001', DB::table('driver_profiles')->where('id', $id)->value('license_number'));

        $this->legacyMigration()->up();

        $raw = DB::table('driver_profiles')->where('id', $id)->first();
        $this->assertStringNotContainsString('LEGACY-LIC-0001', $raw->license_number);
        $this->assertSame('LEGACY-LIC-0001', Crypt::decryptString($raw->license_number));
        $this->assertSame('7001015009087', Crypt::decryptString($raw->id_number));
        $this->assertSame(DriverIdentifiers::blindIndex('LEGACY-LIC-0001'), $raw->license_number_hash);
        $this->assertSame(DriverIdentifiers::blindIndex('7001015009087'), $raw->id_number_hash);

        $model = DriverProfile::withoutGlobalScope('user')->find($id);
        $this->assertSame('LEGACY-LIC-0001', $model->license_number);
    }

    public function test_the_data_migration_is_idempotent_and_never_double_encrypts(): void
    {
        $id = $this->insertLegacyRow('LEGACY-LIC-0002', '7001015009088');
        $already = $this->profileWithIdentifiers();
        $this->legacyMigration()->up();
        $afterFirst = DB::table('driver_profiles')->orderBy('id')->get(['id', 'license_number', 'id_number', 'license_number_hash'])->all();

        $this->legacyMigration()->up();
        $this->legacyMigration()->up();

        $this->assertEquals($afterFirst, DB::table('driver_profiles')->orderBy('id')->get(['id', 'license_number', 'id_number', 'license_number_hash'])->all());
        $this->assertSame('LEGACY-LIC-0002', DriverProfile::withoutGlobalScope('user')->find($id)->license_number);
        $this->assertSame(self::LICENCE, DriverProfile::withoutGlobalScope('user')->find($already->id)->license_number);
    }

    public function test_the_data_migration_reports_what_it_changed(): void
    {
        $this->insertLegacyRow('LEGACY-LIC-0003', '7001015009089');
        $this->insertLegacyRow('LEGACY-LIC-0004', '7001015009090');
        $this->profileWithIdentifiers();

        $first = DriverIdentifiers::encryptLegacyRows();
        $second = DriverIdentifiers::encryptLegacyRows();

        $this->assertSame(2, $first['rows']);
        $this->assertSame(0, $second['rows']);
    }

    public function test_two_legacy_rows_that_collide_once_normalised_do_not_break_the_migration(): void
    {
        $a = $this->insertLegacyRow('SAME-LICENCE-1', '7001015009091');
        $b = $this->insertLegacyRow('same-licence-1 ', '7001015009092');

        $this->legacyMigration()->up();

        $hashes = DB::table('driver_profiles')->whereIn('id', [$a, $b])->pluck('license_number_hash')->all();
        $this->assertCount(1, array_filter($hashes), 'only one row keeps the index; the clash is logged for a human');
        $this->assertSame('same-licence-1 ', DriverProfile::withoutGlobalScope('user')->find($b)->license_number);
    }

    // ─── Masking in lists ────────────────────────────────────────────────

    private function profileFor(Fleet $fleet, string $licence, string $nationalId, string $status): DriverProfile
    {
        return DriverProfile::factory()->create([
            'fleet_id' => $fleet->id, 'license_number' => $licence, 'id_number' => $nationalId, 'status' => $status,
        ]);
    }

    public function test_the_fleet_page_shows_only_the_last_four_characters_to_the_fleet_owner_and_operators(): void
    {
        $fleet = Fleet::factory()->create();
        $this->profileFor($fleet, 'ZA-LICENCE-557731', '8001015009087', 'pending');
        $this->profileFor($fleet, 'ZA-LICENCE-662842', '8002025009088', 'approved');

        foreach ([$fleet->owner, $this->makeOperator()] as $viewer) {
            $this->actingAs($viewer)->get(route('fleets.show', $fleet))
                ->assertOk()
                ->assertDontSee('ZA-LICENCE-557731')
                ->assertDontSee('ZA-LICENCE-662842')
                ->assertDontSee('8001015009087')
                ->assertDontSee('8002025009088')
                ->assertSee('•••• 7731')
                ->assertSee('•••• 9087')
                ->assertSee('•••• 2842');
        }
    }

    // ─── Opening a profile ───────────────────────────────────────────────

    public function test_opening_a_profile_as_the_fleet_owner_shows_the_full_numbers_and_writes_an_audit_row(): void
    {
        $profile = $this->profileWithIdentifiers();
        $owner = $profile->fleet->owner;

        $this->actingAs($owner)->get(route('drivers.show', $profile))
            ->assertOk()
            ->assertSee(self::LICENCE)
            ->assertSee(self::NATIONAL_ID);

        $row = AuditLog::where('action', 'driver_profile.opened')->firstOrFail();
        $this->assertSame($owner->id, $row->actor_user_id);
        $this->assertSame($profile->id, (int) $row->subject_id);
        $this->assertSame(['license_number', 'id_number'], $row->meta['fields']);
        $this->assertSame('fleet_owner', $row->meta['as']);
        $this->assertStringNotContainsString(self::LICENCE, json_encode($row->meta));
    }

    public function test_an_operator_opening_a_profile_is_logged_as_an_operator(): void
    {
        $profile = $this->profileWithIdentifiers();
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('drivers.show', $profile))->assertOk()->assertSee(self::LICENCE);

        $this->assertSame('operator', AuditLog::where('action', 'driver_profile.opened')->firstOrFail()->meta['as']);
    }

    public function test_every_open_is_a_row(): void
    {
        $profile = $this->profileWithIdentifiers();
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('drivers.show', $profile));
        $this->actingAs($operator)->get(route('drivers.show', $profile));

        $this->assertSame(2, AuditLog::where('action', 'driver_profile.opened')->count());
    }

    public function test_a_driver_viewing_their_own_profile_sees_their_numbers_and_is_not_logged(): void
    {
        $profile = $this->profileWithIdentifiers();

        $this->actingAs($profile->user)->get(route('drivers.show', $profile))
            ->assertOk()
            ->assertSee(self::LICENCE);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_a_refused_viewer_sees_nothing_and_leaves_no_row(): void
    {
        $profile = $this->profileWithIdentifiers();
        $stranger = User::factory()->withPersonalTeam()->create();
        $otherFleetOwner = Fleet::factory()->create()->owner;

        foreach ([$stranger, $otherFleetOwner] as $viewer) {
            $this->actingAs($viewer)->get(route('drivers.show', $profile))->assertNotFound();
        }

        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_rows_cannot_be_changed_or_removed(): void
    {
        $profile = $this->profileWithIdentifiers();
        $row = AuditLog::record('driver_profile.opened', null, $profile, ['fields' => []]);

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('an audit row was updated');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $row->delete();
            $this->fail('an audit row was deleted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('driver_profile.opened', $row->fresh()->action);
    }
}
