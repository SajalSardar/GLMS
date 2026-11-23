<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateVersionRequest;
use App\Http\Resources\Api\V1\VersionResource;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VersionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $versions = Version::query()
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return VersionResource::collection($versions);
    }

    public function show(Version $version): VersionResource
    {
        return new VersionResource($version);
    }

    public function update(UpdateVersionRequest $request, Version $version): VersionResource
    {
        $this->authorize('update', $version);

        $version->update($request->validated());

        return new VersionResource($version);
    }
}
