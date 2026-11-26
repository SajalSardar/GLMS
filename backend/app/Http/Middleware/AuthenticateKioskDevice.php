<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateKioskDevice
{
    /**
     * Stands in for `auth:sanctum` on the one route a physical device (not
     * a logged-in portal user) calls. Resolves the device from a
     * SHA-256-hashed `X-Kiosk-Token` header and binds it onto the request
     * for the controller — `school_id` for every punch always comes from
     * this resolved device, never from the request body.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Kiosk-Token');

        if (! $token) {
            abort(401, 'Missing kiosk device token.');
        }

        $device = KioskDevice::where('token', hash('sha256', $token))
            ->where('is_active', true)
            ->first();

        if ($device === null) {
            abort(401, 'Invalid or inactive kiosk device token.');
        }

        // saveQuietly(): a device touching its own last_used_at on every
        // punch should not run through Auditable's saving hook (there is
        // no authenticated portal user to stamp as updated_by here anyway).
        $device->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('kiosk_device', $device);

        return $next($request);
    }
}
