<?php

namespace App\Policies;

use App\Models\Subject;
use Illuminate\Contracts\Auth\Authenticatable;

class SubjectPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('subjects.view');
    }

    public function view(Authenticatable $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('subjects.create');
    }

    public function update(Authenticatable $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.update');
    }

    public function delete(Authenticatable $user, Subject $subject): bool
    {
        return $user->hasPermission('subjects.delete');
    }
}
