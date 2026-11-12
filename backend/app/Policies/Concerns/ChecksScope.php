<?php

namespace App\Policies\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;

trait ChecksScope
{
    /**
     * A user scoped to a single school may only act within that school;
     * a user scoped to a district (no school) may only act within that
     * district; an unscoped user (e.g. a national administrator) has no
     * restriction.
     */
    private function inScope(Authenticatable $user, ?int $schoolId, ?int $districtId): bool
    {
        if ($user->school_id !== null) {
            return $user->school_id === $schoolId;
        }

        if ($user->district_id !== null) {
            return $user->district_id === $districtId;
        }

        return true;
    }
}
