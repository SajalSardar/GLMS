<?php

namespace App\Policies;

use App\Models\ClassRoutine;
use App\Models\User;

class ClassRoutinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function view(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('class_routines.create');
    }

    public function update(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.update');
    }

    public function delete(User $user, ClassRoutine $routine): bool
    {
        return $user->hasPermission('class_routines.delete');
    }
}
