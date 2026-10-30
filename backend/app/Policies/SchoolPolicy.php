<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class SchoolPolicy
{
    use ChecksScope;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schools.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, School $school): bool
    {
        return $user->hasPermission('schools.view') && $this->inScope($user, $school->id, $school->district_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('schools.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, School $school): bool
    {
        return $user->hasPermission('schools.update') && $this->inScope($user, $school->id, $school->district_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, School $school): bool
    {
        return $user->hasPermission('schools.delete') && $this->inScope($user, $school->id, $school->district_id);
    }
}
