<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Holiday;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private const PERMISSIONS = ['schools.view', 'teacher_profiles.view', 'student_profiles.view', 'attendances.view', 'holidays.view'];

    private School $schoolA;

    private School $schoolB;

    private SchoolClass $class;

    private ClassVersion $version;

    private Shift $shift;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School B']);

        // Only needed to satisfy attendances' NOT NULL class/shift/section/
        // class_version foreign keys — the dashboard's attendance counts
        // don't themselves care which section a row belongs to.
        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $this->shift = Shift::create(['class_version_id' => $this->version->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Main']);
    }

    private function attendanceFor(StudentProfile $student, Carbon $date, string $status): Attendance
    {
        return Attendance::create([
            'student_profile_id' => $student->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->shift->id,
            'class_version_id' => $this->version->id,
            'date' => $date,
            'status' => $status,
        ]);
    }

    private function studentIn(School $school, string $name): StudentProfile
    {
        $user = StudentUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => $name]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'student_id' => StudentProfile::nextStudentId(),
        ]);
    }

    private function teacherIn(School $school): TeacherProfile
    {
        $user = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);

        return TeacherProfile::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'employee_id' => 'EMP-'.$user->id,
        ]);
    }

    public function test_an_actor_with_no_permissions_sees_every_section_as_null(): void
    {
        $actor = $this->actorWithNoPermissions();

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'schools_count' => null,
                    'teachers_count' => null,
                    'students_count' => null,
                    'attendance_today' => null,
                    'attendance_trend' => null,
                    'upcoming_holidays' => null,
                    'recent_students' => null,
                ],
            ]);
    }

    public function test_a_teacher_actor_can_load_the_summary_without_a_type_error(): void
    {
        // Regression: the controller's private helpers were once typed to
        // App\Models\User specifically, so a TeacherUser/StudentUser/
        // GuardianUser calling this same shared endpoint (every portal's
        // dashboard hits it, not just management) threw a TypeError.
        $this->teacherIn($this->schoolA);
        $actor = $this->teacherActorWithPermissions(self::PERMISSIONS, $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.teachers_count', 1);
    }

    public function test_a_student_actor_can_load_the_summary_without_a_type_error(): void
    {
        $this->studentIn($this->schoolA, 'Student A');
        $actor = $this->studentActorWithPermissions(self::PERMISSIONS, $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.students_count', 1);
    }

    public function test_a_guardian_actor_can_load_the_summary_without_a_type_error(): void
    {
        $actor = $this->guardianActorWithPermissions(self::PERMISSIONS, $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_an_unscoped_actor_sees_counts_across_every_school(): void
    {
        $this->teacherIn($this->schoolA);
        $this->teacherIn($this->schoolB);
        $this->studentIn($this->schoolA, 'Student A');
        $this->studentIn($this->schoolB, 'Student B');

        $actor = $this->actorWithPermissions(self::PERMISSIONS);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.schools_count', 2);
        $response->assertJsonPath('data.teachers_count', 2);
        $response->assertJsonPath('data.students_count', 2);
    }

    public function test_a_school_scoped_actor_sees_counts_narrowed_to_their_own_school(): void
    {
        $this->teacherIn($this->schoolA);
        $this->teacherIn($this->schoolB);
        $this->studentIn($this->schoolA, 'Student A');
        $this->studentIn($this->schoolB, 'Student B');
        $this->studentIn($this->schoolA, 'Second Student A');

        $actor = $this->actorWithPermissions(self::PERMISSIONS, $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.schools_count', 1);
        $response->assertJsonPath('data.teachers_count', 1);
        $response->assertJsonPath('data.students_count', 2);
    }

    public function test_attendance_today_reports_correct_counts_and_percent(): void
    {
        $student1 = $this->studentIn($this->schoolA, 'Student 1');
        $student2 = $this->studentIn($this->schoolA, 'Student 2');
        $student3 = $this->studentIn($this->schoolA, 'Student 3');
        $student4 = $this->studentIn($this->schoolA, 'Student 4');

        foreach ([[$student1, 'present'], [$student2, 'present'], [$student3, 'present'], [$student4, 'absent']] as [$student, $status]) {
            $this->attendanceFor($student, Carbon::today(), $status);
        }

        $actor = $this->actorWithPermissions(self::PERMISSIONS);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $response->assertJsonPath('data.attendance_today.present', 3);
        $response->assertJsonPath('data.attendance_today.absent', 1);
        $response->assertJsonPath('data.attendance_today.leave', 0);
        $response->assertJsonPath('data.attendance_today.total', 4);
        $response->assertJsonPath('data.attendance_today.present_percent', 75);
        $response->assertJsonPath('data.attendance_today.date', Carbon::today()->toDateString());
    }

    public function test_attendance_trend_covers_the_last_7_days_including_today(): void
    {
        $student = $this->studentIn($this->schoolA, 'Student 1');

        $this->attendanceFor($student, Carbon::today()->subDays(3), 'present');

        $actor = $this->actorWithPermissions(self::PERMISSIONS);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $trend = $response->json('data.attendance_trend');

        $this->assertCount(7, $trend);
        $this->assertSame(Carbon::today()->subDays(6)->toDateString(), $trend[0]['date']);
        $this->assertSame(Carbon::today()->toDateString(), $trend[6]['date']);
        $this->assertSame(1, $trend[3]['present']);
        $this->assertSame(0, $trend[6]['present']);
    }

    public function test_upcoming_holidays_excludes_weekly_and_past_rows_and_is_ordered_by_date(): void
    {
        Holiday::create(['type' => 'weekly', 'day_of_week' => 'friday', 'name' => 'Friday', 'is_active' => true]);
        Holiday::create(['type' => 'government', 'start_date' => Carbon::today()->subDays(5), 'end_date' => Carbon::today()->subDays(5), 'name' => 'Past Holiday', 'is_active' => true]);
        Holiday::create(['type' => 'government', 'start_date' => Carbon::today()->addDays(10), 'end_date' => Carbon::today()->addDays(10), 'name' => 'Later Holiday', 'is_active' => true]);
        Holiday::create(['type' => 'government', 'start_date' => Carbon::today()->addDays(2), 'end_date' => Carbon::today()->addDays(2), 'name' => 'Sooner Holiday', 'is_active' => true]);

        $actor = $this->actorWithPermissions(self::PERMISSIONS);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $holidays = $response->json('data.upcoming_holidays');

        $this->assertCount(2, $holidays);
        $this->assertSame('Sooner Holiday', $holidays[0]['name']);
        $this->assertSame('Later Holiday', $holidays[1]['name']);
    }

    public function test_recent_students_returns_the_five_most_recently_created(): void
    {
        // Explicit, strictly increasing created_at per row — six rows
        // created back-to-back in the same test can otherwise land on the
        // same second, making "most recently created" ambiguous.
        for ($i = 1; $i <= 6; $i++) {
            Carbon::setTestNow(Carbon::today()->setTime(9, 0)->addMinutes($i));
            $this->studentIn($this->schoolA, "Student {$i}");
        }
        Carbon::setTestNow();

        $actor = $this->actorWithPermissions(self::PERMISSIONS);

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk();

        $recent = $response->json('data.recent_students');

        $this->assertCount(5, $recent);
        $this->assertSame('Student 6', $recent[0]['name']);
    }
}
