<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreKioskDeviceRequest;
use App\Http\Requests\Api\V1\UpdateKioskDeviceRequest;
use App\Http\Resources\Api\V1\KioskDeviceResource;
use App\Models\KioskDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class KioskDeviceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', KioskDevice::class);

        $devices = KioskDevice::query()->with('school')->orderBy('name')->paginate(15);

        return KioskDeviceResource::collection($devices);
    }

    /**
     * The plaintext token is generated here and returned exactly once —
     * only its SHA-256 hash is ever stored, so this response is the only
     * place it is ever visible again.
     */
    public function store(StoreKioskDeviceRequest $request): JsonResponse
    {
        $actor = $request->user();
        $plainToken = Str::random(48);

        $device = KioskDevice::create([
            'school_id' => $actor->school_id ?? $request->integer('school_id'),
            'name' => $request->string('name')->toString(),
            'token' => hash('sha256', $plainToken),
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                ...(new KioskDeviceResource($device->load('school')))->resolve(),
                'token' => $plainToken,
            ],
        ], 201);
    }

    public function update(UpdateKioskDeviceRequest $request, KioskDevice $kiosk_device): KioskDeviceResource
    {
        $kiosk_device->update($request->only(['name', 'is_active']));

        return new KioskDeviceResource($kiosk_device->load('school'));
    }

    public function destroy(KioskDevice $kiosk_device): Response
    {
        $this->authorize('delete', $kiosk_device);

        $kiosk_device->delete();

        return response()->noContent();
    }
}
