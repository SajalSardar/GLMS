<?php

namespace App\Policies;

use App\Models\Upazila;
use App\Models\User;

class UpazilaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('upazilas.view');
    }

    public function view(User $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('upazilas.create');
    }

    public function update(User $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.update');
    }

    public function delete(User $user, Upazila $upazila): bool
    {
        return $user->hasPermission('upazilas.delete');
    }
}
