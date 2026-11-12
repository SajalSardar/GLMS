<?php

namespace App\Policies;

use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class TeacherUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
