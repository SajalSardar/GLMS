<?php

namespace App\Policies;

use App\Models\Classroom;
use Illuminate\Contracts\Auth\Authenticatable;

class ClassroomPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('classrooms.view');
    }

    public function view(Authenticatable $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('classrooms.create');
    }

    public function update(Authenticatable $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.update');
    }

    public function delete(Authenticatable $user, Classroom $classroom): bool
    {
        return $user->hasPermission('classrooms.delete');
    }
}
