<?php

namespace App\Policies;

use App\Models\Role;
use Illuminate\Contracts\Auth\Authenticatable;

class RolePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(Authenticatable $user, Role $role): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    public function update(Authenticatable $user, Role $role): bool
    {
        return $user->hasPermission('roles.update');
    }

    public function delete(Authenticatable $user, Role $role): bool
    {
        return $user->hasPermission('roles.delete');
    }

    /**
     * Updating a role's permission set is as sensitive as updating the
     * role itself, so it uses the same ability as `update`.
     */
    public function updatePermissions(Authenticatable $user, Role $role): bool
    {
        return $this->update($user, $role);
    }
}
