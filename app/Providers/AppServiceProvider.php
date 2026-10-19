<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        // Production deployments are HTTPS-only (the PWA and camera capture
        // require it); forcing the scheme keeps every generated URL
        // (redirects, pagination, asset links) on HTTPS even if a proxy
        // hop speaks plain HTTP.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
