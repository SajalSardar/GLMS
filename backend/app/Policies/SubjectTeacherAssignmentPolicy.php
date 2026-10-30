<?php

namespace App\Policies;

use App\Models\School;
use App\Models\SubjectTeacherAssignment;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class SubjectTeacherAssignmentPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view');
    }

    public function view(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.view') && $this->inScope($user, $assignment->school_id, $assignment->district_id);
    }

    /**
     * Create takes the target School (not yet an existing assignment), so
     * the scope check runs against where the assignment would be created.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('subject_teacher_assignments.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.update') && $this->inScope($user, $assignment->school_id, $assignment->district_id);
    }

    public function delete(User $user, SubjectTeacherAssignment $assignment): bool
    {
        return $user->hasPermission('subject_teacher_assignments.delete') && $this->inScope($user, $assignment->school_id, $assignment->district_id);
    }
}
