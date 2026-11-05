<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TimetableEntryControllerUpdateTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_update_rejects_a_time_that_falls_outside_the_shift_hours(): void
    {
        // Before this plan's Task 1 extraction, assertValid() read
        // $attributes['start_time'] directly, which PHP-warned (and
        // Laravel's error handler 500'd) on the missing array key —
        // update() never actually validated this. The extraction to
        // $attributes['start_time'] ?? null fixed the 500 but, until
        // update()'s $merged array included start_time/end_time, it
        // silently skipped the shift-hours/break-overlap hard constraint
        // on every PATCH instead of just not crashing.
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

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'A',
        ]);

        $subject = Subject::create(['name' => 'Math']);
        $classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => 2]);
        $teacher = User::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);
        SubjectTeacherAssignment::create([
            'section_id' => $section->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        $entry = TimetableEntry::create([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'start_time' => '08:00',
            'end_time' => '08:50',
            'status' => 'draft',
        ]);

        $allowed = $this->actorWithPermissions(['timetable_entries.update'], $school->id, $school->district_id);

        // 13:00-13:50 falls entirely outside the shift's 08:00-13:00
        // window — this must be rejected as a 422, not silently accepted.
        $response = $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/timetable-entries/{$entry->id}", [
                'start_time' => '13:00',
                'end_time' => '13:50',
            ]);

        $response->assertUnprocessable();

        $entry->refresh();
        $this->assertSame('08:00', $entry->start_time->format('H:i'));
        $this->assertSame('08:50', $entry->end_time->format('H:i'));
    }
}
