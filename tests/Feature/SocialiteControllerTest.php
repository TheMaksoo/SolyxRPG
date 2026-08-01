<?php

namespace Tests\Feature;

use Tests\TestCase;

class SocialiteControllerTest extends TestCase
{
    public function test_discord_redirect_requires_provider_configuration(): void
    {
        config()->set('services.discord.client_id', null);
        config()->set('services.discord.client_secret', null);
        config()->set('services.discord.redirect', null);

        $response = $this->get('/api/auth/discord/redirect');

        $response->assertRedirect('/landing?oauth_error=unconfigured');
    }

    public function test_discord_redirect_uses_the_normalized_callback_url(): void
    {
        config()->set('services.discord.client_id', 'discord-client-id');
        config()->set('services.discord.client_secret', 'discord-client-secret');
        config()->set('services.discord.redirect', 'https://solyxrpg.com/api/auth/discord/callback');

        $response = $this->get('/api/auth/discord/redirect');

        $response->assertRedirectContains('https://discord.com/api/oauth2/authorize');
        $response->assertRedirectContains('client_id=discord-client-id');
        $response->assertRedirectContains('redirect_uri=https%3A%2F%2Fsolyxrpg.com%2Fapi%2Fauth%2Fdiscord%2Fcallback');
        $this->assertStringNotContainsString(
            'redirect_uri=https%3A%2F%2Fsolyxrpg.com%2F%2Fapi%2Fauth%2Fdiscord%2Fcallback',
            (string) $response->headers->get('Location')
        );
        $this->assertStringNotContainsString('prompt=none', (string) $response->headers->get('Location'));
    }
}
