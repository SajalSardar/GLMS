<?php

namespace App\Policies;

use App\Models\District;
use App\Models\User;

class DistrictPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('districts.view');
    }

    public function view(User $user, District $district): bool
    {
        return $user->hasPermission('districts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('districts.create');
    }

    public function update(User $user, District $district): bool
    {
        return $user->hasPermission('districts.update');
    }

    public function delete(User $user, District $district): bool
    {
        return $user->hasPermission('districts.delete');
    }
}
