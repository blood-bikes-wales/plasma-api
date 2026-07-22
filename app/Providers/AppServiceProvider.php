<?php

namespace App\Providers;

use App\Contracts\GoogleIdTokenVerifier;
use App\Services\Auth\GoogleApiClientIdTokenVerifier;
use Google\Client;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
