<?php

namespace App\Policies;

use App\Models\StudentUser;
use Illuminate\Contracts\Auth\Authenticatable;

class StudentUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
