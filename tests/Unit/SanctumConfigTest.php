<?php

namespace Tests\Unit;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanctumConfigTest extends TestCase
{
    public function test_default_stateful_domains_include_current_request_host_placeholder(): void
    {
        config()->set('app.url', 'https://solyxrpg.com');
        putenv('SANCTUM_STATEFUL_DOMAINS');

        try {
            $config = require __DIR__.'/../../config/sanctum.php';

            $this->assertContains('solyxrpg.com', $config['stateful']);
            $this->assertContains(Sanctum::$currentRequestHostPlaceholder, $config['stateful']);
        } finally {
            putenv('SANCTUM_STATEFUL_DOMAINS');
        }
    }
}
