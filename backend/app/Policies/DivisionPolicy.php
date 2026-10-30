<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;

class DivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('divisions.view');
    }

    public function view(User $user, Division $division): bool
    {
        return $user->hasPermission('divisions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('divisions.create');
    }

    public function update(User $user, Division $division): bool
    {
        return $user->hasPermission('divisions.update');
    }

    public function delete(User $user, Division $division): bool
    {
        return $user->hasPermission('divisions.delete');
    }
}
