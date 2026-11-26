<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocalFrontendCorsTest extends TestCase
{
    public function test_https_frontend_can_make_credentialed_api_requests(): void
    {
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
