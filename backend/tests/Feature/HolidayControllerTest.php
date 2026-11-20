<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class HolidayControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_is_public(): void
    {
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);

        $this->getJson('/api/v1/holidays')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.day_of_week', 'friday');
    }

    public function test_national_admin_can_create_a_weekly_holiday(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'day_of_week' => 'saturday',
            'name' => 'Saturday',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'weekly')
            ->assertJsonPath('data.day_of_week', 'saturday')
            ->assertJsonPath('data.start_date', null);
    }

    public function test_national_admin_can_create_a_government_holiday(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);
        $year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'government',
            'start_date' => '2026-03-26',
            'end_date' => '2026-03-26',
            'name' => 'Independence Day',
            'bn_name' => 'স্বাধীনতা দিবস',
            'academic_year_id' => $year->id,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'government')
            ->assertJsonPath('data.start_date', '2026-03-26')
            ->assertJsonPath('data.academic_year.id', $year->id);
    }

    public function test_store_rejects_a_weekly_holiday_without_day_of_week(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'name' => 'No day',
        ])->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
    }

    public function test_store_rejects_a_weekly_holiday_that_also_sets_dates(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'day_of_week' => 'friday',
            'start_date' => '2026-01-02',
            'name' => 'Friday',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_store_rejects_a_duplicate_weekly_day(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'day_of_week' => 'friday',
            'name' => 'Friday again',
        ])->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
    }

    public function test_store_rejects_a_government_holiday_without_dates(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'government',
            'name' => 'No dates',
        ])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_a_school_admin_without_create_permission_cannot_create_a_holiday(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['holidays.view']);

        $this->actingAs($schoolAdmin, 'sanctum')->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'day_of_week' => 'friday',
            'name' => 'Friday',
        ])->assertForbidden();
    }

    public function test_store_is_forbidden_for_a_guest(): void
    {
        $this->postJson('/api/v1/holidays', [
            'type' => 'weekly',
            'day_of_week' => 'friday',
            'name' => 'Friday',
        ])->assertUnauthorized();
    }

    public function test_national_admin_can_update_and_deactivate_a_holiday(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create', 'holidays.update']);
        $holiday = Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/holidays/{$holiday->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_update_rejects_moving_a_government_holiday_to_an_invalid_date_range(): void
    {
        $admin = $this->actorWithPermissions(['holidays.create', 'holidays.update']);
        $holiday = Holiday::create([
            'type' => 'government', 'start_date' => '2026-03-26', 'end_date' => '2026-03-26', 'name' => 'Independence Day',
        ]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/holidays/{$holiday->id}", [
            'end_date' => '2026-03-20',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_a_non_national_admin_cannot_delete_a_holiday(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['holidays.view']);
        $holiday = Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/holidays/{$holiday->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($holiday);
    }

    public function test_national_admin_can_delete_a_holiday(): void
    {
        $admin = $this->actorWithPermissions(['holidays.delete']);
        $holiday = Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/holidays/{$holiday->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($holiday);
    }

    public function test_is_holiday_matches_weekly_and_dated_rows(): void
    {
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday']);
        Holiday::create(['type' => 'government', 'start_date' => '2026-03-26', 'end_date' => '2026-03-26', 'name' => 'Independence Day']);
        Holiday::create(['type' => 'government', 'start_date' => '2026-04-10', 'end_date' => '2026-04-12', 'name' => 'Eid Break', 'is_active' => false]);

        $this->assertTrue(Holiday::isHoliday(new \DateTime('2026-11-27'))); // a Friday
        $this->assertTrue(Holiday::isHoliday(new \DateTime('2026-03-26'))); // dated holiday
        // Falls inside the Eid Break row's range, but that row is inactive.
        $this->assertFalse(Holiday::isHoliday(new \DateTime('2026-04-11')));
        $this->assertFalse(Holiday::isHoliday(new \DateTime('2026-11-25'))); // a Wednesday, no holiday
    }
}
