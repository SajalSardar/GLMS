<?php

namespace Tests\Unit;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use App\Models\TimetableEntry;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TimetableEntryCreateOrRestoreTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Subject $subject;

    private TeacherUser $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);
        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Version']);
        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section']);
        $this->subject = Subject::create(['name' => 'Math']);
        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'classroom_id' => null,
            'academic_year_id' => null,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];
    }

    public function test_creates_a_fresh_draft_when_no_trashed_row_occupies_the_slot(): void
    {
        $entry = TimetableEntry::createOrRestore($this->attributes());

        $this->assertDatabaseHas('timetable_entries', [
            'id' => $entry->id,
            'section_id' => $this->section->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_restores_and_overwrites_a_trashed_row_at_the_same_slot(): void
    {
        $original = TimetableEntry::create([...$this->attributes(), 'status' => 'published', 'published_at' => now()]);
        $originalId = $original->id;
        $original->delete();

        // createOrRestore() is a raw persistence operation with no
        // business-rule checks of its own (those live in
        // TimetableConflictChecker, exercised separately) — no
        // SubjectTeacherAssignment is needed to prove it overwrites
        // teacher_id correctly.
        $otherTeacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $restored = TimetableEntry::createOrRestore([
            ...$this->attributes(),
            'teacher_id' => $otherTeacher->id,
        ]);

        $this->assertSame($originalId, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('draft', $restored->status);
        $this->assertNull($restored->published_at);
        $this->assertNull($restored->published_by);
        $this->assertSame($otherTeacher->id, $restored->teacher_id);
    }

    public function test_store_restores_a_previously_soft_deleted_entry_at_the_same_slot(): void
    {
        $original = TimetableEntry::create([...$this->attributes(), 'status' => 'draft']);
        $originalId = $original->id;
        $original->delete();

        $allowed = $this->actorWithPermissions(['timetable_entries.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/timetable-entries', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ]);

        $response->assertCreated();
        $this->assertSame($originalId, $response->json('data.id'));
        $this->assertDatabaseHas('timetable_entries', ['id' => $originalId, 'deleted_at' => null, 'status' => 'draft']);
    }
}
