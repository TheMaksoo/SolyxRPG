<?php

namespace Tests\Feature;

use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SocialiteRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_redirect_returns_failed_error_when_provider_initialization_throws(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andThrow(new \RuntimeException('Socialite driver init failed'));

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect('/landing?oauth_error=failed');
    }
}
