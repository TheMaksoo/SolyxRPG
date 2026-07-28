<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(100);
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', DiscordProvider::class);
        });

        // Keyed by email+IP (not just IP) so credential stuffing spread across many accounts from
        // one IP is still capped per-account, and a distributed attack on one account is still
        // capped per-IP — either alone would leave a gap the other closes.
        RateLimiter::for('login', function ($request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // The battle page's client tick loop syncs regen every 5 (real) seconds, so ~12/min is normal —
        // 20/min leaves room for a reconnect or a second tab without opening the door to a client whose
        // tick loop has been sped up to hammer the endpoint. Keyed by user, not IP, so it still caps a
        // single account across devices/proxies.
        RateLimiter::for('regen-sync', function ($request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
