<?php

namespace App\Policies;

use App\Models\GuardianUser;
use App\Models\StudentProfile;
use App\Models\StudentUser;
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
     * exam scores and video-watching history. student_profiles.user_id
     * is student-only (see migration 2026_11_12_100100), so the
     * identity check must also confirm $user is a StudentUser —
     * otherwise a same-numeric-id row in a different user table would
     * wrongly match.
     */
    public function viewDashboard(Authenticatable $user, StudentProfile $profile): bool
    {
        if ($user instanceof StudentUser && $user->getAuthIdentifier() === $profile->user_id) {
            return true;
        }

        if ($user->hasPermission('student_progress.view')) {
            return true;
        }

        if (! $user instanceof GuardianUser || $user->profile === null) {
            return false;
        }

        return $user->profile->students()->where('student_profiles.id', $profile->id)->exists();
    }
}
