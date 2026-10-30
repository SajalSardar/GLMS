<?php

namespace App\Policies;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class TeacherProfilePolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.view');
    }

    public function view(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.view') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teacher_profiles.create');
    }

    public function update(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.update') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }

    public function delete(User $user, TeacherProfile $profile): bool
    {
        return $user->hasPermission('teacher_profiles.delete') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }
}
