<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateOAuthConfig extends Command
{
    protected $signature = 'oauth:validate';
    protected $description = 'Validate OAuth and session configuration for production deployment';

    public function handle(): int
    {
        $this->info('🔍 Validating OAuth Configuration...');
        $this->newLine();

        $errors = [];
        $warnings = [];

        // Check APP_URL
        $appUrl = config('app.url');
        $this->info("APP_URL: {$appUrl}");
        if (empty($appUrl)) {
            $errors[] = 'APP_URL is not set';
        } elseif (! str_starts_with($appUrl, 'http')) {
            $errors[] = 'APP_URL must start with http:// or https://';
        }

        // Check SESSION_DOMAIN
        $sessionDomain = config('session.domain');
        $this->info("SESSION_DOMAIN: ".($sessionDomain ?: 'null (uses current domain)'));
        
        if (config('app.env') !== 'local' && empty($sessionDomain)) {
            $warnings[] = 'SESSION_DOMAIN should be set in production (e.g., .solyx.gg for all subdomains)';
        }

        // Check SANCTUM_STATEFUL_DOMAINS
        $statefulDomains = config('sanctum.stateful');
        $this->info('SANCTUM_STATEFUL_DOMAINS: '.implode(', ', $statefulDomains));
        
        if (empty($statefulDomains)) {
            $errors[] = 'SANCTUM_STATEFUL_DOMAINS is empty';
        } else {
            $appHost = parse_url($appUrl, PHP_URL_HOST);
            if ($appHost && ! in_array($appHost, $statefulDomains, true)) {
                $errors[] = "SANCTUM_STATEFUL_DOMAINS must include the APP_URL domain: {$appHost}";
            }
        }

        // Check CORS allowed origins
        $corsOrigins = config('cors.allowed_origins');
        $this->info('CORS_ALLOWED_ORIGINS: '.implode(', ', $corsOrigins));
        
        if (empty($corsOrigins) || in_array('*', $corsOrigins, true)) {
            $errors[] = 'CORS_ALLOWED_ORIGINS must be explicitly set (not *)';
        } elseif (! in_array($appUrl, $corsOrigins, true)) {
            $errors[] = "CORS_ALLOWED_ORIGINS must include APP_URL: {$appUrl}";
        }

        // Check SESSION_SECURE_COOKIE for production HTTPS
        $secureSession = config('session.secure');
        $this->info('SESSION_SECURE_COOKIE: '.($secureSession ? 'true' : 'false'));
        
        if (str_starts_with($appUrl, 'https://') && ! $secureSession) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true when using HTTPS';
        }

        // Check OAuth provider configuration
        $this->newLine();
        $this->info('OAuth Providers:');
        
        foreach (['discord', 'google'] as $provider) {
            $clientId = config("services.{$provider}.client_id");
            $clientSecret = config("services.{$provider}.client_secret");
            $redirect = config("services.{$provider}.redirect");
            
            $configured = filled($clientId) && filled($clientSecret);
            $status = $configured ? '✅' : '❌';
            $this->line("  {$status} {$provider}: ".($configured ? 'configured' : 'not configured'));
            
            if ($configured && $redirect) {
                $this->line("      Redirect URI: {$redirect}");
                if (! str_starts_with($redirect, $appUrl)) {
                    $warnings[] = "{$provider} redirect URI doesn't match APP_URL";
                }
            }
        }

        // Display results
        $this->newLine();
        
        if (! empty($errors)) {
            $this->error('❌ Configuration Errors:');
            foreach ($errors as $error) {
                $this->line("  • {$error}");
            }
            $this->newLine();
        }

        if (! empty($warnings)) {
            $this->warn('⚠️  Configuration Warnings:');
            foreach ($warnings as $warning) {
                $this->line("  • {$warning}");
            }
            $this->newLine();
        }

        if (empty($errors) && empty($warnings)) {
            $this->info('✅ All OAuth configuration checks passed!');
            return self::SUCCESS;
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->error('💡 For production deployment on play.solyx.gg, your .env should include:');
            $this->line('');
            $this->line('APP_URL=https://play.solyx.gg');
            $this->line('SESSION_DOMAIN=.solyx.gg');
            $this->line('SESSION_SECURE_COOKIE=true');
            $this->line('SANCTUM_STATEFUL_DOMAINS=play.solyx.gg');
            $this->line('CORS_ALLOWED_ORIGINS=https://play.solyx.gg');
            $this->line('');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
