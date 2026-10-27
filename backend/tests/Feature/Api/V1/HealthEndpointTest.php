<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['status', 'timestamp']])
            ->assertJsonPath('data.status', 'ok');
    }
}
