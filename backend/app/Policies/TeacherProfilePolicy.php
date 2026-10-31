<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use App\Models\User;

class TeacherProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function view(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.create');
    }

    public function update(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.update');
    }

    public function delete(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.delete');
    }
}
