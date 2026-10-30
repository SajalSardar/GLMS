<?php

namespace App\Policies;

use App\Models\School;
use App\Models\Shift;
use App\Models\User;
use App\Policies\Concerns\ChecksScope;

class ShiftPolicy
{
    use ChecksScope;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('shifts.view');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.view') && $this->inScope($user, $shift->school_id, $shift->district_id);
    }

    /**
     * Create takes the target School (not yet an existing Shift), so the
     * scope check runs against where the shift would be created.
     */
    public function create(User $user, School $school): bool
    {
        return $user->hasPermission('shifts.create') && $this->inScope($user, $school->id, $school->district_id);
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.update') && $this->inScope($user, $shift->school_id, $shift->district_id);
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->hasPermission('shifts.delete') && $this->inScope($user, $shift->school_id, $shift->district_id);
    }
}
