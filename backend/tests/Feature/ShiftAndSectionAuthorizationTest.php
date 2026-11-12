<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ShiftAndSectionAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private ClassVersion $classVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class']);

        $this->classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Version A',
        ]);
    }

    public function test_shift_store_and_update_are_authorized(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->classVersion->school_id,
            'district_id' => $this->classVersion->district_id,
            'name' => 'Existing Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);
        $payload = [
            'class_version_id' => $this->classVersion->id,
            'name' => 'New Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/shifts', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['shifts.create', 'shifts.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/shifts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/shifts/{$shift->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_section_store_and_update_are_authorized(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->classVersion->school_id,
            'district_id' => $this->classVersion->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $shift->school_id,
            'district_id' => $shift->district_id,
            'name' => 'Existing Section',
        ]);
        $payload = ['shift_id' => $shift->id, 'name' => 'New Section'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/sections', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['sections.create', 'sections.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/sections', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    /**
     * Regression test for SectionController::assertTeacherBelongsToSchool():
     * a real TeacherUser from the shift's own school, submitted as
     * class_teacher_id, must be accepted (not rejected with "does not
     * belong to this section's school").
     */
    public function test_section_store_and_update_accept_a_teacher_from_the_shift_school(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->classVersion->school_id,
            'district_id' => $this->classVersion->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $teacher = TeacherUser::factory()->create(['school_id' => $shift->school_id, 'district_id' => $shift->district_id]);

        $section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $shift->school_id,
            'district_id' => $shift->district_id,
            'name' => 'Existing Section',
        ]);
        $payload = ['shift_id' => $shift->id, 'name' => 'New Section', 'class_teacher_id' => $teacher->id];

        $allowed = $this->actorWithPermissions(['sections.create', 'sections.update']);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/sections', $payload)
            ->assertCreated()
            ->assertJsonPath('data.class_teacher.id', $teacher->id);

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/sections/{$section->id}", ['class_teacher_id' => $teacher->id])
            ->assertOk()
            ->assertJsonPath('data.class_teacher.id', $teacher->id);
    }
}
