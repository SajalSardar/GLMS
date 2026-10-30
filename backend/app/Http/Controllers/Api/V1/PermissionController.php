<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource (the permission catalog).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('permissions.view'), 403);

        return PermissionResource::collection(Permission::orderBy('name')->get());
    }
}
