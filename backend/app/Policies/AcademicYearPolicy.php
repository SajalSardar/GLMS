<?php

namespace App\Policies;

use App\Models\AcademicYear;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicYearPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function view(Authenticatable $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_years.create');
    }

    public function update(Authenticatable $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.update');
    }

    public function delete(Authenticatable $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.delete');
    }

    /**
     * Marking a year as the current one is as sensitive as updating it,
     * so it uses the same ability as `update`.
     */
    public function setCurrent(Authenticatable $user, AcademicYear $academicYear): bool
    {
        return $this->update($user, $academicYear);
    }
}
