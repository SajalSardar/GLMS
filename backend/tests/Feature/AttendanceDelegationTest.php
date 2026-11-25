<?php

namespace Tests\Feature;

use App\Models\AcademicExamTerm;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentLeaveApplication;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherLeaveApplication;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AttendanceDelegationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);
    }

    private function studentInSection(): StudentProfile
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        return StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
            'roll_number' => '1',
        ]);
    }

    public function test_an_approved_delegate_can_record_attendance_during_the_leave_window(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [
                ['student_profile_id' => $profile->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('data.students.0.status', 'present');
    }

    public function test_a_named_delegate_cannot_record_before_the_application_is_approved(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'pending',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_delegate_cannot_record_outside_the_leave_date_range(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_teacher_not_named_as_delegate_cannot_record(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $uninvolvedTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($uninvolvedTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_my_sections_lists_the_delegated_section_with_can_record_true(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $response = $this->actingAs($substitute, 'sanctum')
            ->getJson('/api/v1/attendances/my-sections')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->section->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['can_record']);
    }

    /**
     * Non-regression: an ordinary class teacher with no delegation
     * involved records exactly as before.
     */
    public function test_the_class_teacher_can_still_record_their_own_section_normally(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $profile = $this->studentInSection();

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertOk();
    }

    /**
     * AttendancePolicy::viewSection()'s delegate branch, exercised
     * directly rather than only transitively through recordSection() —
     * every other test in this file goes through record()/my-sections.
     * viewSection() also gates roster/report, which an approved delegate
     * must be able to reach to actually take over the class for the
     * leave window (they need to see who to mark, not just submit marks
     * blind).
     */
    public function test_an_approved_delegate_can_view_the_roster_and_report_for_the_delegated_section(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')
            ->getJson("/api/v1/attendances/roster?section_id={$this->section->id}&date=".now()->toDateString())
            ->assertOk();

        $this->actingAs($substitute, 'sanctum')
            ->getJson('/api/v1/attendances/report?section_id='.$this->section->id.'&year='.now()->format('Y').'&month='.now()->format('n'))
            ->assertOk();
    }

    /**
     * Non-regression, pinning the spec's explicit Non-Goal: an active
     * attendance delegation must NOT extend to exam-mark recording or
     * student-leave review — the one place a future maintainer might
     * assume delegation should "just work" everywhere class_teacher_id
     * appears. AcademicExamMarkPolicy and StudentLeaveApplicationPolicy
     * are untouched by this feature and know nothing about
     * TeacherLeaveApplication, so the same substitute who may record
     * attendance for this exact section/leave-window must still be
     * forbidden from both.
     */
    public function test_an_approved_attendance_delegation_does_not_extend_to_exam_marks_or_student_leave_review(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();
        $classId = $this->section->shift->classVersion->class_id;

        // (i) Exam-mark recording for the delegated section is still
        // forbidden — AcademicExamMarkPolicy only grants recordSection()
        // to the section's own class teacher or a SubjectTeacherAssignment
        // holder, neither of which the substitute is.
        $subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $classId, 'subject_id' => $subject->id, 'cq_full_marks' => 70]);
        $term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $this->section->id,
            'subject_id' => $subject->id,
            'academic_exam_term_id' => $term->id,
            'academic_year_id' => $year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 50]],
        ])->assertForbidden();

        // (ii) Reviewing a student-leave application for a student in the
        // delegated section is still forbidden — StudentLeaveApplicationPolicy
        // ::review() only grants it to the section's own class teacher.
        $studentLeaveApplication = StudentLeaveApplication::create([
            'student_profile_id' => $profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $classId,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'pending',
        ]);

        $this->actingAs($substitute, 'sanctum')
            ->patchJson("/api/v1/student-leave-applications/{$studentLeaveApplication->id}/review", [
                'status' => 'approved',
            ])
            ->assertForbidden();
    }
}
