<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class StudentProfilePolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function view(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.view') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_profiles.create');
    }

    public function update(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.update') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }

    public function delete(User $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.delete') && $this->inScope($user, $profile->school_id, $profile->district_id);
    }
}
