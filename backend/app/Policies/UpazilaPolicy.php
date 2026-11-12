<?php

namespace App\Policies;

use App\Models\Upazila;
use Illuminate\Contracts\Auth\Authenticatable;

class UpazilaPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('upazilas.view');
    }

    public function view(Authenticatable $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('upazilas.create');
    }

    public function update(Authenticatable $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.update');
    }

    public function delete(Authenticatable $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.delete');
    }
}
