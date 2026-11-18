<?php

namespace App\Policies;

use App\Models\ClassSchool;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassSchoolPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('class_schools.view');
    }

    public function view(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('class_schools.create');
    }

    public function delete(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.delete');
    }

    /**
     * Configuring this assignment's subject list is as sensitive as
     * creating/deleting the assignment itself, so it gets its own
     * permission rather than reusing create/delete.
     */
    public function updateSubjects(Authenticatable $user, ClassSchool $classSchool): bool
    {
        return $user->hasPermission('class_schools.updateSubjects');
    }
}
