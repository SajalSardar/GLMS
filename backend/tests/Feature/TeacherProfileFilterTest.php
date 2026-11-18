<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherProfileFilterTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_can_be_filtered_by_user_id(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $teacherA = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);
        $teacherB = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);

        $profileA = TeacherProfile::create(['user_id' => $teacherA->id, 'school_id' => $school->id, 'district_id' => $district->id, 'designation' => 'Senior Teacher']);
        TeacherProfile::create(['user_id' => $teacherB->id, 'school_id' => $school->id, 'district_id' => $district->id, 'designation' => 'Junior Teacher']);

        $actor = $this->actorWithPermissions(['teacher_profiles.view']);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/v1/teacher-profiles?user_id={$teacherA->id}")
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $profileA->id);
    }
}
