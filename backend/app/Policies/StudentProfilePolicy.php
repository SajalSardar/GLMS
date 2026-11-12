<?php

namespace App\Policies;

use App\Models\StudentProfile;
use Illuminate\Contracts\Auth\Authenticatable;

class StudentProfilePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function view(Authenticatable $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('student_profiles.create');
    }

    public function update(Authenticatable $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.update');
    }

    public function delete(Authenticatable $user, StudentProfile $profile): bool
    {
        return $user->hasPermission('student_profiles.delete');
    }

    /**
     * Linking a guardian to a student is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function attachGuardian(Authenticatable $user, StudentProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    /**
     * Moving a student between sections is as sensitive as updating the
     * profile itself, so it uses the same ability as `update`.
     */
    public function transferSection(Authenticatable $user, StudentProfile $profile): bool
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
    public function viewDashboard(Authenticatable $user, StudentProfile $profile): bool
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
