<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamMarkPolicy
{
    /**
     * Whether $user may view marks for $section/$subjectId — the
     * section's own class teacher, a teacher holding a
     * SubjectTeacherAssignment for this exact (section, subject), or a
     * staff actor with academic_exam_marks.view (TenantScope-limited via
     * $section's own lookup). No publish-state check — staff may audit
     * entered-but-unpublished marks.
     */
    public function viewSection(Authenticatable $user, Section $section, int $subjectId): bool
    {
        return $this->canTouch($user, $section, $subjectId, 'academic_exam_marks.view');
    }

    /**
     * Whether $user may enter/update marks for $section/$subjectId —
     * identical teacher-side condition to viewSection() (unlike
     * AttendancePolicy, a subject teacher may both view AND record their
     * own subject, per the explicit "class teacher enters any subject;
     * subject teacher enters their own" rule), differing only in which
     * permission a staff actor needs.
     */
    public function recordSection(Authenticatable $user, Section $section, int $subjectId): bool
    {
        return $this->canTouch($user, $section, $subjectId, 'academic_exam_marks.update');
    }

    private function canTouch(Authenticatable $user, Section $section, int $subjectId, string $staffPermission): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)
                    ->where('subject_id', $subjectId)
                    ->where('teacher_id', $user->id)
                    ->exists();
        }

        return $user->hasPermission($staffPermission);
    }
}
