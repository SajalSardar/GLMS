<?php

namespace App\Policies;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class ClassPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.view') && $this->inScope($user, $class->school_id, $class->district_id);
    }

    /**
     * Create takes the target School (not yet an existing SchoolClass), so
     * the scope check runs against where the class would be created:
     * `$user->can('create', [SchoolClass::class, $school])`.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('classes.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.update') && $this->inScope($user, $class->school_id, $class->district_id);
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.delete') && $this->inScope($user, $class->school_id, $class->district_id);
    }

    /**
     * Configuring a class's subject list is as sensitive as updating the
     * class itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(User $user, SchoolClass $class): bool
    {
        return $this->update($user, $class);
    }
}
