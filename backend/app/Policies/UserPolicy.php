<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('users.delete');
    }

    /**
     * Determine whether the user can change the target user's role/scope.
     * Same ability as a general update.
     */
    public function assignRole(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
