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
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_teacher_returns_only_that_teachers_own_assignments_with_relations_loaded(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Section A']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $otherSubject = Subject::create(['name' => 'English']);

        $teacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);
        $otherTeacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);

        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'school_id' => $school->id, 'district_id' => $school->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $otherTeacher->id, 'school_id' => $school->id, 'district_id' => $school->district_id]);

        $rows = SubjectTeacherAssignment::forTeacher($teacher->id);

        $this->assertCount(1, $rows);
        $this->assertSame($teacher->id, $rows->first()->teacher_id);
        $this->assertTrue($rows->first()->relationLoaded('section'));
        $this->assertTrue($rows->first()->relationLoaded('subject'));
        $this->assertSame('Section A', $rows->first()->section->name);
        $this->assertSame('Mathematics', $rows->first()->subject->name);
    }
}
