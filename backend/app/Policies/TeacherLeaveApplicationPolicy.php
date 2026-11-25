<?php

namespace App\Policies;

use App\Models\TeacherLeaveApplication;
use Illuminate\Contracts\Auth\Authenticatable;

class TeacherLeaveApplicationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_leave_applications.view');
    }

    public function view(Authenticatable $user, TeacherLeaveApplication $application): bool
    {
        return $user->hasPermission('teacher_leave_applications.view');
    }

    /**
     * Unlike StudentLeaveApplicationPolicy::review(), there is no per-row
     * ownership check here — the reviewer is always school_admin (one
     * reviewer role per school), and TenantScope already narrows which
     * rows a school_admin can even reach. national_admin reaches this too
     * via its all-permissions wildcard (this app never excludes
     * national_admin from an ability by role-name check) — the "read-
     * only for national_admin" product decision is enforced by simply not
     * building review UI for it, not by a backend lockout.
     */
    public function review(Authenticatable $user, TeacherLeaveApplication $application): bool
    {
        return $user->hasPermission('teacher_leave_applications.update');
    }
}
