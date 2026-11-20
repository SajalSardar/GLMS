<?php

namespace App\Policies;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicSubjectMarkConfigurePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.view');
    }

    public function view(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.create');
    }

    public function update(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.update');
    }

    public function delete(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.delete');
    }
}
