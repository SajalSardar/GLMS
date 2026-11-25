<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class AttendancePolicy
{
    /**
     * Whether $user may view (roster or monthly report) attendance for
     * $section — the section's own class teacher, any teacher holding a
     * SubjectTeacherAssignment there (view-only), an approved substitute
     * delegate standing in for the class teacher during their leave, or a
     * staff actor with attendances.view (scoped only by TenantScope).
     */
    public function viewSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)->where('teacher_id', $user->id)->exists()
                || $this->isApprovedDelegateFor($user, $section);
        }

        return $user->hasPermission('attendances.view');
    }

    /**
     * Whether $user may record/update attendance for $section — the
     * section's own class teacher, or an approved substitute delegate
     * standing in for them during their leave (see isApprovedDelegateFor
     * below). Every other teacher assigned there is view-only. A non-
     * teacher staff actor with the broader attendances.update permission
     * can still override, scoped only by TenantScope.
     */
    public function recordSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || $this->isApprovedDelegateFor($user, $section);
        }

        return $user->hasPermission('attendances.update');
    }

    /**
     * $user stands in for $section's own class teacher today: an
     * approved TeacherLeaveApplication names $user as the substitute for
     * $section's class_teacher_id, and today falls within its date
     * range. Evaluated fresh on every call — no flag to flip when the
     * leave starts or ends, just this date-range-and-status predicate
     * (the same one TeacherLeaveApplicationController::index()'s
     * `current=1` filter and the national_admin "Teachers on Leave" view
     * use).
     */
    private function isApprovedDelegateFor(TeacherUser $user, Section $section): bool
    {
        if ($section->class_teacher_id === null) {
            return false;
        }

        return TeacherLeaveApplication::query()
            ->where('teacher_id', $section->class_teacher_id)
            ->where('delegate_teacher_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', now())
            ->whereDate('to_date', '>=', now())
            ->exists();
    }
}
