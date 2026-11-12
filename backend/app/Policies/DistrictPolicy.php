<?php

namespace App\Policies;

use App\Models\District;
use Illuminate\Contracts\Auth\Authenticatable;

class DistrictPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('districts.view');
    }

    public function view(Authenticatable $user, District $district): bool
    {
        return $user->hasPermission('districts.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('districts.create');
    }

    public function update(Authenticatable $user, District $district): bool
    {
        return $user->hasPermission('districts.update');
    }

    public function delete(Authenticatable $user, District $district): bool
    {
        return $user->hasPermission('districts.delete');
    }
}
