<?php

namespace App\Policies;

use App\Models\Division;
use Illuminate\Contracts\Auth\Authenticatable;

class DivisionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('divisions.view');
    }

    public function view(Authenticatable $user, Division $division): bool
    {
        return $user->hasPermission('divisions.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('divisions.create');
    }

    public function update(Authenticatable $user, Division $division): bool
    {
        return $user->hasPermission('divisions.update');
    }

    public function delete(Authenticatable $user, Division $division): bool
    {
        return $user->hasPermission('divisions.delete');
    }
}
