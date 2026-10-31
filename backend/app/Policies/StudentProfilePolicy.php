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

    /**
     * Linking a guardian to a student is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function attachGuardian(User $user, StudentProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    /**
     * Moving a student between sections is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function transferSection(User $user, StudentProfile $profile): bool
    {
        return $this->update($user, $profile);
    }
}
