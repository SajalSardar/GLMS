<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\StudentLeaveApplication;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class StudentLeaveApplicationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('student_leave_applications.view');
    }

    /**
     * Any permitted actor (a teacher with student_leave_applications.view
     * included) may VIEW any application within their own tenant scope
     * (school/district, via TenantScope) — not just the ones from
     * sections they're the class teacher of. Reviewing (below) is the
     * action that stays locked to the section's own class teacher.
     */
    public function view(Authenticatable $user, StudentLeaveApplication $application): bool
    {
        return $user->hasPermission('student_leave_applications.view');
    }

    /**
     * Only the section's own class teacher (Section.class_teacher_id)
     * may change status or leave a comment — the student_leave_
     * applications.update permission the teacher role carries is only
     * what lets a TeacherUser actor reach this endpoint at all, not
     * license to review every student's application school-wide. A
     * non-teacher staff actor (school_admin, national_admin, ...) with
     * the permission can still override, scoped only by TenantScope,
     * same as everywhere else in this app.
     */
    public function review(Authenticatable $user, StudentLeaveApplication $application): bool
    {
        if ($user instanceof TeacherUser) {
            return Section::where('id', $application->section_id)
                ->where('class_teacher_id', $user->id)
                ->exists();
        }

        return $user->hasPermission('student_leave_applications.update');
    }
}
