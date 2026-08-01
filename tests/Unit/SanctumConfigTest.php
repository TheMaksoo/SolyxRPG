<?php

namespace Tests\Unit;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\TestCase;

class SanctumConfigTest extends TestCase
{
    public function test_default_stateful_domains_include_current_request_host_placeholder(): void
    {
        putenv('APP_URL=https://solyxrpg.com');
        putenv('SANCTUM_STATEFUL_DOMAINS');

        try {
            $config = require __DIR__.'/../../config/sanctum.php';

            $this->assertContains('solyxrpg.com', $config['stateful']);
            $this->assertContains(Sanctum::$currentRequestHostPlaceholder, $config['stateful']);
        } finally {
            putenv('APP_URL');
            putenv('SANCTUM_STATEFUL_DOMAINS');
        }
    }
}
