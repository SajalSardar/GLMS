<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function view(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_profiles.create');
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.update');
    }

    public function delete(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.delete');
    }
}
