<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class AcademicYearPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('academic_years.view');
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.view') && $this->inScope($user, $academicYear->school_id, $academicYear->district_id);
    }

    /**
     * Create takes the target School (not yet an existing AcademicYear), so
     * the scope check runs against where the year would be created:
     * `$user->can('create', [AcademicYear::class, $school])`.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('academic_years.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.update') && $this->inScope($user, $academicYear->school_id, $academicYear->district_id);
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $user->hasPermission('academic_years.delete') && $this->inScope($user, $academicYear->school_id, $academicYear->district_id);
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
