<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('academic_years.create');
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.update');
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.delete');
    }

    /**
     * Marking a year as the current one is as sensitive as updating it,
     * so it uses the same ability as `update`.
     */
    public function setCurrent(User $user, AcademicYear $academicYear): bool
    {
        return $this->update($user, $academicYear);
    }
}
