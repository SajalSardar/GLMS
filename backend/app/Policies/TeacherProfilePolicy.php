<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use Illuminate\Contracts\Auth\Authenticatable;

class TeacherProfilePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function view(Authenticatable $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_profiles.create');
    }

    public function update(Authenticatable $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.update');
    }

    public function delete(Authenticatable $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.delete');
    }
}
