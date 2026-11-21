<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentProfileControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class6;

    private SchoolClass $class7;

    private Section $section6;

    private Section $section7;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class6 = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->class7 = SchoolClass::create(['name' => 'Class 7', 'numeric_level' => 7]);
        ClassSchool::create(['class_id' => $this->class6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        ClassSchool::create(['class_id' => $this->class7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $version6 = ClassVersion::create(['class_id' => $this->class6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift6 = Shift::create(['class_version_id' => $version6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section6 = Section::create(['shift_id' => $shift6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $version7 = ClassVersion::create(['class_id' => $this->class7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift7 = Shift::create(['class_version_id' => $version7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section7 = Section::create(['shift_id' => $shift7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);
    }

    private function studentInSection6(?string $rollNumber = null): StudentProfile
    {
        $student = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        return StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class6->id,
            'section_id' => $this->section6->id,
            'shift_id' => $this->section6->shift_id,
            'class_version_id' => $this->section6->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);
    }

    /**
     * Regression test: transferSection() must also update class_id when
     * the destination section belongs to a different class than the
     * student's current one — previously only section_id/shift_id/
     * class_version_id were kept in sync, leaving class_id stale and
     * poisoning any feature that snapshots $profile->class_id from a
     * seemingly-authoritative source.
     */
    public function test_transfer_section_into_a_different_class_updates_class_id(): void
    {
        $profile = $this->studentInSection6();
        $this->assertSame($this->class6->id, $profile->class_id);

        $allowed = $this->actorWithPermissions(['student_profiles.update', 'student_profiles.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/student-profiles/{$profile->id}/transfer-section", ['to_section_id' => $this->section7->id])
            ->assertCreated();

        $profile->refresh();
        $this->assertSame($this->class7->id, $profile->class_id);
        $this->assertSame($this->section7->id, $profile->section_id);
        $this->assertSame($this->section7->shift_id, $profile->shift_id);
        $this->assertSame($this->section7->shift->class_version_id, $profile->class_version_id);

        $this->actingAs($allowed, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.class_id', $this->class7->id);
    }

    /**
     * Regression test: creating a second student with the same
     * (class_id, roll_number) pair as an existing student must fail
     * validation with a clean 422 naming roll_number — not an unhandled
     * 500 QueryException from the DB's unique(['class_id', 'roll_number'])
     * index.
     */
    public function test_store_rejects_a_duplicate_roll_number_within_the_same_class(): void
    {
        $this->studentInSection6('50');

        $newStudent = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $allowed = $this->actorWithPermissions(['student_profiles.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/student-profiles', [
                'user_id' => $newStudent->id,
                'class_id' => $this->class6->id,
                'roll_number' => '50',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roll_number');
    }

    /**
     * Regression test: patching an existing student onto another
     * student's (class_id, roll_number) pair must fail validation
     * cleanly, and must not mutate the row being updated.
     */
    public function test_update_rejects_a_duplicate_roll_number_within_the_same_class(): void
    {
        $this->studentInSection6('50');
        $other = $this->studentInSection6('51');

        $allowed = $this->actorWithPermissions(['student_profiles.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/student-profiles/{$other->id}", ['roll_number' => '50'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roll_number');

        $other->refresh();
        $this->assertSame('51', $other->roll_number);
    }

    /**
     * Regression test: a soft-deleted student's roll_number still blocks
     * a new student from taking the same (class_id, roll_number) pair —
     * the restore-check in StudentProfileController::store() only matches
     * on user_id, so without a dedicated uniqueness rule this trashed
     * collision would otherwise hit the DB as an unhandled 500.
     */
    public function test_store_rejects_a_duplicate_roll_number_that_belongs_to_a_soft_deleted_profile(): void
    {
        $trashed = $this->studentInSection6('51');
        $trashed->delete();

        $newStudent = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $allowed = $this->actorWithPermissions(['student_profiles.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/student-profiles', [
                'user_id' => $newStudent->id,
                'class_id' => $this->class6->id,
                'roll_number' => '51',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roll_number');
    }

    /**
     * A PATCH that leaves roll_number untouched (or resubmits the same
     * value) while changing other fields must not self-reject against
     * the student's own existing row — the ignore() on the unique rule
     * has to actually exclude it.
     */
    public function test_update_keeps_its_own_unchanged_roll_number(): void
    {
        $profile = $this->studentInSection6('52');
        $allowed = $this->actorWithPermissions(['student_profiles.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/student-profiles/{$profile->id}", [
                'roll_number' => '52',
                'blood_group' => 'O+',
            ])
            ->assertOk()
            ->assertJsonPath('data.roll_number', '52')
            ->assertJsonPath('data.blood_group', 'O+');
    }

    /**
     * The uniqueness rule is scoped to class_id, not global — two
     * students in different classes may legitimately share the same
     * roll_number.
     */
    public function test_store_allows_the_same_roll_number_in_a_different_class(): void
    {
        $this->studentInSection6('50');

        $newStudent = StudentUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $allowed = $this->actorWithPermissions(['student_profiles.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/student-profiles', [
                'user_id' => $newStudent->id,
                'class_id' => $this->class7->id,
                'roll_number' => '50',
            ])
            ->assertCreated()
            ->assertJsonPath('data.roll_number', '50')
            ->assertJsonPath('data.class_id', $this->class7->id);
    }
}
