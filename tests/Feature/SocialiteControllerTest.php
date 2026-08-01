<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use Tests\TestCase;

class SocialiteControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('player');
            $table->boolean('is_tester')->default(false);
            $table->boolean('tester_mode_disabled')->default(false);
            $table->timestamp('banned_at')->nullable();
            $table->unsignedBigInteger('active_character_id')->nullable();
            $table->text('preferences')->nullable();
            $table->timestamp('tos_accepted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->string('discord_id')->nullable();
            $table->timestamps();
        });
    }

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

    public function test_oauth_callback_keeps_user_authenticated_through_spa_boot_requests(): void
    {
        $oauthUser = new OAuthUser;
        $oauthUser->id = 'google-user-123';
        $oauthUser->name = 'OAuth Player';
        $oauthUser->email = 'oauth-player@example.test';
        $oauthUser->user = ['email_verified' => true];

        Socialite::shouldReceive('driver->user')->once()->andReturn($oauthUser);

        $callback = $this->get('/api/auth/google/callback?code=oauth-code');

        $callback->assertRedirect('/character/create');

        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'oauth-player@example.test');
    }
}
