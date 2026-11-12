<?php

namespace App\Policies;

use App\Models\SubjectTeacherAssignment;
use Illuminate\Contracts\Auth\Authenticatable;

class SubjectTeacherAssignmentPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function view(Authenticatable $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.create');
    }

    public function update(Authenticatable $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.update');
    }

    public function delete(Authenticatable $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.delete');
    }
}
