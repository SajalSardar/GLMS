<?php

namespace App\Policies;

use App\Models\Holiday;
use Illuminate\Contracts\Auth\Authenticatable;

class HolidayPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('holidays.view');
    }

    public function view(Authenticatable $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holidays.view');
    }

    /**
     * Only a national_admin may create/update/delete — the holiday
     * calendar is national, not per-school (see the create migration's
     * own comment), so only the .create/.update/.delete permissions
     * (granted to national_admin alone — see RolePermissionSeeder) gate
     * these, with no additional school/district scoping to apply.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('holidays.create');
    }

    public function update(Authenticatable $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holidays.update');
    }

    public function delete(Authenticatable $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holidays.delete');
    }
}
