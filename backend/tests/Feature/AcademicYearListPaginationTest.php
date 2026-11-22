<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearListPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_year_index_is_paginated(): void
    {
        $currentYear = (int) now()->format('Y');

        for ($i = 0; $i < 18; $i++) {
            $year = $currentYear - $i;
            AcademicYear::create([
                'name' => "Year {$year}",
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
            ]);
        }

        $response = $this->getJson('/api/v1/academic-years');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
    }

    public function test_academic_year_index_excludes_years_after_the_current_year(): void
    {
        $currentYear = (int) now()->format('Y');

        $thisYear = AcademicYear::create([
            'name' => (string) $currentYear,
            'start_date' => "{$currentYear}-01-01",
            'end_date' => "{$currentYear}-12-31",
        ]);
        AcademicYear::create([
            'name' => (string) ($currentYear + 1),
            'start_date' => ($currentYear + 1).'-01-01',
            'end_date' => ($currentYear + 1).'-12-31',
        ]);

        $response = $this->getJson('/api/v1/academic-years');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $thisYear->id);
    }

    public function test_academic_year_index_filters_by_search(): void
    {
        AcademicYear::create(['name' => 'Session 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        AcademicYear::create(['name' => 'Session 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->getJson('/api/v1/academic-years?search=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Session 2026');
    }

    public function test_set_current_endpoint_unsets_any_other_year_nationally(): void
    {
        $yearA = AcademicYear::create(['name' => 'Year A', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
        $yearB = AcademicYear::create(['name' => 'Year B', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $role = Role::create(['name' => 'role_academic_year_test', 'label' => 'Test Role']);
        $permission = Permission::firstOrCreate(['name' => 'academic_years.update'], ['label' => 'academic_years.update']);
        $role->permissions()->attach($permission);
        $actor = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/academic-years/{$yearB->id}/set-current")
            ->assertOk();

        $this->assertFalse($yearA->refresh()->is_current);
        $this->assertTrue($yearB->refresh()->is_current);
    }
}
