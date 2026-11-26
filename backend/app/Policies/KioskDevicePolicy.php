<?php

namespace App\Policies;

use App\Models\KioskDevice;
use Illuminate\Contracts\Auth\Authenticatable;

class KioskDevicePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('kiosk_devices.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('kiosk_devices.create');
    }

    public function update(Authenticatable $user, KioskDevice $device): bool
    {
        return $user->hasPermission('kiosk_devices.update');
    }

    public function delete(Authenticatable $user, KioskDevice $device): bool
    {
        return $user->hasPermission('kiosk_devices.delete');
    }
}
