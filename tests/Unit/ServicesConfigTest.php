<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServicesConfigTest extends TestCase
{
    public function test_social_redirect_urls_trim_a_trailing_slash_from_app_url(): void
    {
        putenv('APP_URL=https://solyxrpg.com/');

        try {
            $config = require __DIR__.'/../../config/services.php';

            $this->assertSame('https://solyxrpg.com/api/auth/discord/callback', $config['discord']['redirect']);
            $this->assertSame('https://solyxrpg.com/api/auth/google/callback', $config['google']['redirect']);
        } finally {
            putenv('APP_URL');
        }
    }
}
