<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalFrontendCorsTest extends TestCase
{
    public function test_https_frontend_can_make_credentialed_api_requests(): void
    {
        // Pin the allowed origin so the assertion does not depend on the
        // container's CORS_ALLOWED_ORIGINS value.
        config(['cors.allowed_origins' => ['https://special-delicacies.local']]);

        $response = $this->withHeaders([
            'Origin' => 'https://special-delicacies.local',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/auth/login');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://special-delicacies.local')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
