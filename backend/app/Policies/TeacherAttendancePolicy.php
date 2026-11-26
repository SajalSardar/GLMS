<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class TeacherAttendancePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_attendances.view');
    }

    public function update(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_attendances.update');
    }
}
