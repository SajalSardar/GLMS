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

    /**
     * Viewing a student's learning dashboard: the student themselves,
     * their linked guardian, or a management/oversight role holding
     * student_progress.view — deliberately NOT view()'s flat
     * student_profiles.view permission, which the student role already
     * holds broadly and would let any student see any other student's
     * exam scores and video-watching history.
     */
    public function viewDashboard(User $user, StudentProfile $profile): bool
    {
        if ($user->id === $profile->user_id) {
            return true;
        }

        if ($user->hasPermission('student_progress.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('student_profiles.id', $profile->id)->exists();
    }
}
