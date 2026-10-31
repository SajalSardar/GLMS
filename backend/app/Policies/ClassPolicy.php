<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

class ClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.update');
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission('classes.delete');
    }

    /**
     * Configuring a class's subject list is as sensitive as updating the
     * class itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(User $user, SchoolClass $class): bool
    {
        return $this->update($user, $class);
    }
}
