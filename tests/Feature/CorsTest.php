<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_preflight_allows_configured_spa_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://controller.example']]);

        $this->preflight('http://localhost:5173')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Max-Age', '3600');
    }

    public function test_preflight_does_not_echo_unknown_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://controller.example']]);

        $this->preflight('https://evil.example')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_preflight_denies_all_origins_when_none_configured(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->preflight('http://localhost:5173')
            ->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function preflight(string $origin)
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization',
        ])->options('/api/me');
    }
}
