<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('api-key', function (Request $request): Limit {
            $apiKey = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);
            $key = $apiKey instanceof ApiKey ? 'api-key:'.$apiKey->token : 'ip:'.$request->ip();

            return Limit::perMinute(60)->by($key);
        });
    }
}
