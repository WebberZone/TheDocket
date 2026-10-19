<?php

namespace App\Providers;

use App\Services\Fetching\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One resolver per process; tests swap it for a fixed host map.
        $this->app->singleton(HostResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production deployments are HTTPS-only (the PWA install and the
        // share target require it); forcing the scheme keeps every
        // generated URL (redirects, pagination, asset links) on HTTPS even
        // if a proxy hop speaks plain HTTP.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
