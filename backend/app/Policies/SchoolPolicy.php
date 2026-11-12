<?php

namespace App\Policies;

use App\Models\School;
use Illuminate\Contracts\Auth\Authenticatable;

class SchoolPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('schools.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Authenticatable $user, School $school): bool
    {
        return $user->hasPermission('schools.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('schools.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Authenticatable $user, School $school): bool
    {
        return $user->hasPermission('schools.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Authenticatable $user, School $school): bool
    {
        return $user->hasPermission('schools.delete');
    }
}
