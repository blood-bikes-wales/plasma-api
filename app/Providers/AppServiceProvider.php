<?php

namespace App\Providers;

use App\Contracts\GoogleIdTokenVerifier;
use App\Services\Auth\GoogleApiClientIdTokenVerifier;
use App\Services\ThreeRings\ThreeRingsClient;
use App\Services\ThreeRings\ThreeRingsConfig;
use Google\Client;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdTokenVerifier::class, function (): GoogleIdTokenVerifier {
            return new GoogleApiClientIdTokenVerifier(
                new Client(['client_id' => config('services.google.client_id')]),
            );
        });


        $this->app->singleton(ThreeRingsClient::class, function (Application $app): ThreeRingsClient {
            $services = $app->make('config')->get('services.three_rings');

            return new ThreeRingsClient(
                http: $app->make(HttpFactory::class),
                limiter: $app->make(RateLimiter::class),
                cache: $app->make(CacheRepository::class),
                logger: $app->make(LoggerInterface::class),
                config: ThreeRingsConfig::fromArray([
                    ...$services,
                    'user_agent' => sprintf(
                        '%s (%s)',
                        $app->make('config')->get('app.name'),
                        $services['contact_email'] ?? '',
                    ),
                ]),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
