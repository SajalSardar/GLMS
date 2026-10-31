<?php

namespace App\Policies;

use App\Models\SubjectTeacherAssignment;
use App\Models\User;

class SubjectTeacherAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function view(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.create');
    }

    public function update(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.update');
    }

    public function delete(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.delete');
    }
}
