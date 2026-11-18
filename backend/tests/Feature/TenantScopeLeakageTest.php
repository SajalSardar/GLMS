<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\District;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeLeakageTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $yearA;

    private AcademicYear $yearB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School B',
        ]);

        $this->yearA = AcademicYear::create([
            'name' => 'Year A',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->yearB = AcademicYear::create([
            'name' => 'Year B',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ]);
    }

    public function test_actor_with_permission_can_delete_their_own_school_academic_year(): void
    {
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'school_admin_delete', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearA->id}");

        $response->assertNoContent();
    }

    public function test_school_scoped_actor_can_delete_any_national_academic_year_with_permission(): void
    {
        // academic_years is now national content — not scoped by school_id — so any
        // school-scoped actor with academic_years.delete permission can delete any
        // academic year, not just years associated with their school.
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'school_admin_delete_2', 'label' => 'School Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearB->id}");

        $response->assertNoContent();
    }

    public function test_actor_without_permission_gets_403_deleting_their_own_school_academic_year(): void
    {
        $role = Role::create(['name' => 'school_admin_no_perm', 'label' => 'School Admin']);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearA->id}");

        $response->assertForbidden();
    }

    public function test_unscoped_actor_with_permission_can_delete_either_schools_academic_year(): void
    {
        $permission = Permission::create(['name' => 'academic_years.delete', 'label' => 'Delete academic years']);
        $role = Role::create(['name' => 'national_admin_delete', 'label' => 'National Admin']);
        $role->permissions()->attach($permission);

        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/academic-years/{$this->yearB->id}");

        $response->assertNoContent();
    }
}
