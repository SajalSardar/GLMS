<?php

namespace App\Policies;

use App\Models\ClassRoutine;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassRoutinePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function view(Authenticatable $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('class_routines.create');
    }

    public function update(Authenticatable $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.update');
    }

    public function delete(Authenticatable $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.delete');
    }
}
