<?php

namespace App\Services\ThreeRings;

final readonly class ThreeRingsConfig
{
    /**
     * @param  array<string, array{fresh: int, stale: int}>  $cacheTtls
     */
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public string $userAgent,
        public int $maxAttempts,
        public int $decaySeconds,
        public array $cacheTtls,
    ) {}

    /**
     * @param  array{base_url: string, key: ?string, user_agent: string, rate_limit: array{max_attempts: int, decay_seconds: int}, cache: array<string, array{fresh: int, stale: int}>}  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            baseUrl: $config['base_url'],
            apiKey: (string) ($config['key'] ?? ''),
            userAgent: $config['user_agent'],
            maxAttempts: $config['rate_limit']['max_attempts'],
            decaySeconds: $config['rate_limit']['decay_seconds'],
            cacheTtls: $config['cache'],
        );
    }

    public function freshTtl(string $endpoint): int
    {
        return $this->cacheTtls[$endpoint]['fresh'];
    }

    public function staleTtl(string $endpoint): int
    {
        return $this->cacheTtls[$endpoint]['stale'];
    }
}
