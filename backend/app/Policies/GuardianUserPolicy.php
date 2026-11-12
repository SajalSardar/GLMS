<?php

namespace App\Policies;

use App\Models\GuardianUser;
use Illuminate\Contracts\Auth\Authenticatable;

class GuardianUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
