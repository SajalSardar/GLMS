<?php

namespace App\Support;

use App\Models\Classroom;
use App\Models\Section;
use App\Models\Shift;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Hard-constraint validity and conflict checks for a single TimetableEntry
 * slot, shared by TimetableEntryController's manual store()/update() path
 * and TimetableGenerator's automatic placement path – the two must never
 * independently re-derive the same rule and drift apart. Pure: no HTTP
 * concerns, no exceptions. A null return means "valid"; a
 * ['field' => ..., 'message' => ...] return names the violated field (so
 * callers that throw ValidationException can keep using the exact same
 * field key callers have always used) and a human-readable reason.
 */
class TimetableConflictChecker
{
    /**
     * Configuration guards: the subject must have a valid teacher
     * assignment for this section (hard constraint #9), the day must be
     * one the section's shift actually runs, the period's time (if given)
     * must fall within the shift's hours and outside its breaks, the
     * weekly period cap for the subject must not be exceeded, and room
     * capacity must be sufficient.
     *
     * @param  array{subject_id:int, teacher_id:int, classroom_id:?int, day_of_week:string, period_no:int, start_time?:?string, end_time?:?string}  $attributes
     * @return array{field: string, message: string}|null
     */
    public function invalidReason(Section $section, array $attributes, ?int $exceptId = null): ?array
    {
        $shift = $section->shift;
        $classVersion = $shift->classVersion;

        $assignment = SubjectTeacherAssignment::where('section_id', $section->id)
            ->where('subject_id', $attributes['subject_id'])
            ->first();

        if (! $assignment || $assignment->teacher_id !== $attributes['teacher_id']) {
            return ['field' => 'teacher_id', 'message' => 'This teacher has no valid subject assignment for this section.'];
        }

        if (! in_array($attributes['day_of_week'], $shift->days_of_week, true)) {
            return ['field' => 'day_of_week', 'message' => 'This day is not part of the section\'s shift schedule.'];
        }

        $startTime = $attributes['start_time'] ?? null;
        $endTime = $attributes['end_time'] ?? null;

        if ($startTime !== null && $endTime !== null) {
            $error = $this->shiftHoursError($shift, $startTime, $endTime);

            if ($error !== null) {
                return $error;
            }
        }

        $pivot = $classVersion->subjects()->where('subjects.id', $attributes['subject_id'])->first()?->pivot;
        $requiredWeekly = $pivot?->required_weekly_periods;

        if ($requiredWeekly !== null) {
            $scheduledCount = TimetableEntry::where('section_id', $section->id)
                ->where('subject_id', $attributes['subject_id'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->count();

            if ($scheduledCount >= $requiredWeekly) {
                return ['field' => 'subject_id', 'message' => "This subject already has its configured {$requiredWeekly} weekly period(s) scheduled."];
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $section->school_id) {
                return ['field' => 'classroom_id', 'message' => 'The selected classroom does not belong to this section\'s school.'];
            }

            if ($classroom->capacity !== null && $section->capacity !== null && $classroom->capacity < $section->capacity) {
                return ['field' => 'classroom_id', 'message' => 'This classroom\'s capacity is smaller than the section\'s capacity.'];
            }
        }

        if (User::where('id', $attributes['teacher_id'])->where('school_id', $section->school_id)->doesntExist()) {
            return ['field' => 'teacher_id', 'message' => 'The selected teacher does not belong to this section\'s school.'];
        }

        return null;
    }

    /**
     * @return array{field: string, message: string}|null
     */
    private function shiftHoursError(Shift $shift, string $startTime, string $endTime): ?array
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $shiftStart = Carbon::parse($shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($shift->end_time->format('H:i'));

        if ($start->lessThan($shiftStart) || $end->greaterThan($shiftEnd)) {
            return ['field' => 'start_time', 'message' => 'This period falls outside the section\'s shift hours.'];
        }

        foreach ($shift->breaks ?? [] as $break) {
            $breakStart = Carbon::parse($break['start_time']);
            $breakEnd = Carbon::parse($break['end_time']);

            if ($start->lessThan($breakEnd) && $end->greaterThan($breakStart)) {
                return ['field' => 'start_time', 'message' => 'This period overlaps a configured break interval.'];
            }
        }

        return null;
    }

    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. The section/day/period triplet
     * itself is enforced by a DB unique index instead.
     *
     * @param  array{teacher_id:int, classroom_id:?int, day_of_week:string, period_no:int}  $attributes
     * @return array{field: string, message: string}|null
     */
    public function conflictReason(array $attributes, ?int $exceptId = null): ?array
    {
        $teacherConflict = TimetableEntry::where('teacher_id', $attributes['teacher_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($teacherConflict) {
            return ['field' => 'teacher_id', 'message' => 'This teacher is already assigned to another section in this period.'];
        }

        if ($attributes['classroom_id'] !== null) {
            $classroomConflict = TimetableEntry::where('classroom_id', $attributes['classroom_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('period_no', $attributes['period_no'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($classroomConflict) {
                return ['field' => 'classroom_id', 'message' => 'This classroom is already booked for another section in this period.'];
            }
        }

        return null;
    }
}
