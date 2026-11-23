<?php

namespace App\Policies;

use App\Models\Version;
use Illuminate\Contracts\Auth\Authenticatable;

class VersionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('versions.view');
    }

    public function view(Authenticatable $user, Version $version): bool
    {
        return $user->hasPermission('versions.view');
    }

    public function update(Authenticatable $user, Version $version): bool
    {
        return $user->hasPermission('versions.update');
    }
}
