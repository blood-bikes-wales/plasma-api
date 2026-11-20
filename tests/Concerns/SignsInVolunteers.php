<?php

namespace Tests\Concerns;

use App\Contracts\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Http;

/**
 * Signs requests in as Three Rings volunteers with the Google token verifier mocked.
 * Call useThreeRingsConfig() from setUp().
 */
trait SignsInVolunteers
{
    protected function useThreeRingsConfig(): void
    {
        config()->set('services.three_rings', [
            'base_url' => 'https://www.3r.org.uk',
            'key' => 'test-key',
            'contact_email' => 'dev@bloodbikes.wales',
            'rate_limit' => ['max_attempts' => 15, 'decay_seconds' => 60],
            'cache' => [
                'volunteers' => ['fresh' => 3600, 'stale' => 86400],
                'roles' => ['fresh' => 21600, 'stale' => 172800],
                'shifts' => ['fresh' => 300, 'stale' => 3600],
            ],
        ]);

        Http::preventStrayRequests();
    }

    /**
     * A directory entry whose email is `$email` and who holds the one Three Rings role.
     *
     * @return array<string, mixed>
     */
    protected function volunteer(string $roleName, string $email = 'rider@bloodbikes.wales', int $id = 100001): array
    {
        return [
            'id' => $id,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => $roleName]],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => $email]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $volunteers
     */
    protected function fakeDirectory(array $volunteers): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => $volunteers]),
        ]);
    }

    protected function mockVerifiedClaims(string $email = 'rider@bloodbikes.wales', string $sub = 'google-sub-123'): void
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'hd' => 'bloodbikes.wales',
            'name' => 'Test Rider',
        ];

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
