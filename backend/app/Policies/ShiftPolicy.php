<?php

namespace App\Policies;

use App\Models\Shift;
use Illuminate\Contracts\Auth\Authenticatable;

class ShiftPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('shifts.view');
    }

    public function view(Authenticatable $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('shifts.create');
    }

    public function update(Authenticatable $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.update');
    }

    public function delete(Authenticatable $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.delete');
    }
}
