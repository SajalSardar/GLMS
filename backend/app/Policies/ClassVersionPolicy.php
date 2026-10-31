<?php

namespace App\Policies;

use App\Models\ClassVersion;
use App\Models\User;

class ClassVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function view(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('class_versions.create');
    }

    public function update(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.update');
    }

    public function delete(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.delete');
    }

    /**
     * Configuring a version's subject list is as sensitive as updating
     * the version itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(User $user, ClassVersion $classVersion): bool
    {
        return $this->update($user, $classVersion);
    }
}
