<?php

namespace App\Policies;

use App\Models\SchoolClass;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(Authenticatable $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(Authenticatable $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.update');
    }

    public function delete(Authenticatable $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.delete');
    }
}
