<?php

namespace App\Policies;

use App\Models\ClassVersion;
use App\Models\School;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class ClassVersionPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('class_versions.view');
    }

    public function view(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.view') && $this->inScope($user, $classVersion->school_id, $classVersion->district_id);
    }

    /**
     * Create takes the target School (not yet an existing ClassVersion),
     * so the scope check runs against where the version would be created.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('class_versions.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.update') && $this->inScope($user, $classVersion->school_id, $classVersion->district_id);
    }

    public function delete(User $user, ClassVersion $classVersion): bool
    {
        return $user->hasPermission('class_versions.delete') && $this->inScope($user, $classVersion->school_id, $classVersion->district_id);
    }
}
