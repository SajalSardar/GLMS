<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class ClassroomPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classrooms.view');
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.view') && $this->inScope($user, $classroom->school_id, $classroom->district_id);
    }

    /**
     * Create takes the target School (not yet an existing Classroom), so
     * the scope check runs against where the classroom would be created:
     * `$user->can('create', [Classroom::class, $school])`.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('classrooms.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.update') && $this->inScope($user, $classroom->school_id, $classroom->district_id);
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.delete') && $this->inScope($user, $classroom->school_id, $classroom->district_id);
    }
}
