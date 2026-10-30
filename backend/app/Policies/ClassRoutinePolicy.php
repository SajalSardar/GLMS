<?php

namespace App\Policies;

use App\Models\ClassRoutine;
use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class ClassRoutinePolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function view(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.view') && $this->inScope($user, $routine->school_id, $routine->district_id);
    }

    /**
     * Create takes the target School (not yet an existing ClassRoutine),
     * so the scope check runs against where the entry would be created:
     * `$user->can('create', [ClassRoutine::class, $school])`.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('class_routines.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.update') && $this->inScope($user, $routine->school_id, $routine->district_id);
    }

    public function delete(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.delete') && $this->inScope($user, $routine->school_id, $routine->district_id);
    }
}
