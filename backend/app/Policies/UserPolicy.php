<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class UserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, User $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, User $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, User $target): bool
    {
        return $user->hasPermission('users.delete');
    }

    /**
     * Determine whether the user can change the target user's role/scope.
     * Same ability as a general update.
     */
    public function assignRole(Authenticatable $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
