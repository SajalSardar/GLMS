<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class AttendancePolicy
{
    /**
     * Whether $user may view (roster or monthly report) attendance for
     * $section — the section's own class teacher, any teacher holding a
     * SubjectTeacherAssignment there (view-only, per the feature request),
     * or a staff actor with attendances.view (school_admin/national_admin,
     * scoped only by TenantScope already applied to $section's own
     * lookup).
     */
    public function viewSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)->where('teacher_id', $user->id)->exists();
        }

        return $user->hasPermission('attendances.view');
    }

    /**
     * Whether $user may record/update attendance for $section — only the
     * section's own class teacher may mark it; every other teacher
     * assigned there is view-only (per the feature request). A non-
     * teacher staff actor with the broader attendances.update permission
     * can still override, scoped only by TenantScope, same convention as
     * StudentLeaveApplicationPolicy::review().
     */
    public function recordSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id;
        }

        return $user->hasPermission('attendances.update');
    }
}
