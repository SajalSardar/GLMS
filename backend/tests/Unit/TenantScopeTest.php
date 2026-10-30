<?php

namespace Tests\Unit;

use App\Models\District;
use App\Models\Division;
use App\Models\Role;
use App\Models\School;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create([
            'division_id' => $division->id,
            'district_id' => $districtA->id,
            'upazila_id' => $upazilaA->id,
            'name' => 'School A',
        ]);

        $this->schoolB = School::create([
            'division_id' => $division->id,
            'district_id' => $districtB->id,
            'upazila_id' => $upazilaB->id,
            'name' => 'School B',
        ]);
    }

    public function test_unscoped_actor_sees_both_schools(): void
    {
        $role = Role::create(['name' => 'national_admin', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, School::all());
    }

    public function test_school_scoped_actor_sees_only_their_own_school(): void
    {
        $role = Role::create(['name' => 'school_admin', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = School::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->schoolA->id, $visible->first()->id);
    }

    public function test_district_scoped_actor_sees_only_their_own_district(): void
    {
        $role = Role::create(['name' => 'district_officer', 'label' => 'District Officer']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = School::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->schoolA->id, $visible->first()->id);
    }

    public function test_unauthenticated_request_is_unrestricted(): void
    {
        $this->assertCount(2, School::all());
    }
}
