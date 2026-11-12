<?php

namespace App\Policies;

use App\Models\ClassVersion;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassVersionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function view(Authenticatable $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('class_versions.create');
    }

    public function update(Authenticatable $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.update');
    }

    public function delete(Authenticatable $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.delete');
    }

    /**
     * Configuring a version's subject list is as sensitive as updating
     * the version itself, so it uses the same ability as `update`.
     */
    public function updateSubjects(Authenticatable $user, ClassVersion $classVersion): bool
    {
        return $this->update($user, $classVersion);
    }
}
