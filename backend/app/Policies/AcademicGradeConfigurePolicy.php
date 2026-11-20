<?php

namespace App\Policies;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicGradeConfigurePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_grade_configures.view');
    }

    public function view(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_grade_configures.create');
    }

    public function update(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.update');
    }

    public function delete(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.delete');
    }
}
