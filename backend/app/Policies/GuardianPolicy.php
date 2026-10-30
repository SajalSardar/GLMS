<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;

class GuardianPolicy
{
    /**
     * Guardians aren't pinned to a single school (one guardian can have
     * children at different schools), so scope is checked via
     * Guardian::isVisibleTo() (shared child / scoped student / unscoped)
     * instead of the simple school_id/district_id comparison ChecksScope
     * does for other resources.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('guardians.view');
    }

    public function view(User $user, Guardian $guardian): bool
    {
        return $user->hasPermission('guardians.view') && $guardian->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('guardians.create');
    }

    public function update(User $user, Guardian $guardian): bool
    {
        return $user->hasPermission('guardians.update') && $guardian->isVisibleTo($user);
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return $user->hasPermission('guardians.delete') && $guardian->isVisibleTo($user);
    }
}
