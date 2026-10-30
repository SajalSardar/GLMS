<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class UserPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission('users.view') && $this->inScope($user, $target->school_id, $target->district_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('users.update') && $this->inScope($user, $target->school_id, $target->district_id);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('users.delete') && $this->inScope($user, $target->school_id, $target->district_id);
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
