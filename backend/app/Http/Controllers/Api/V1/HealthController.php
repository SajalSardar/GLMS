<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HealthResource;

class HealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): HealthResource
    {
        return new HealthResource((object) []);
    }
}
