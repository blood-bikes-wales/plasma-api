<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestIdLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{level: string, message: string, context: array<string, mixed>, request_id: mixed}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

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
        Http::fake([
            'www.3r.org.uk/*' => Http::response(['volunteers' => []]),
        ]);

        $this->logged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
                // Context::add metadata is not part of MessageLogged::$context;
                // capture it here so we assert the shared request_id once.
                'request_id' => Context::get('request_id'),
            ];
        });
    }

    public function test_response_includes_generated_request_id(): void
    {
        $response = $this->getJson('/api/');

        $response->assertOk()
            ->assertHeader(AssignRequestId::HEADER);

        $this->assertNotEmpty($response->headers->get(AssignRequestId::HEADER));
    }

    public function test_inbound_request_id_is_echoed_on_response(): void
    {
        $requestId = 'client-journey-abc-123';

        $response = $this->withHeader(AssignRequestId::HEADER, $requestId)
            ->getJson('/api/');

        $response->assertOk()
            ->assertHeader(AssignRequestId::HEADER, $requestId);
    }

    public function test_inbound_correlation_id_is_accepted_as_request_id(): void
    {
        $correlationId = 'corr-456';

        $response = $this->withHeader('X-Correlation-Id', $correlationId)
            ->getJson('/api/');

        $response->assertOk()
            ->assertHeader(AssignRequestId::HEADER, $correlationId);
    }

    public function test_malformed_inbound_request_id_is_replaced(): void
    {
        $response = $this->withHeader(AssignRequestId::HEADER, 'bad id with spaces!')
            ->getJson('/api/');

        $response->assertOk();

        $echoed = $response->headers->get(AssignRequestId::HEADER);

        $this->assertNotSame('bad id with spaces!', $echoed);
        $this->assertNotEmpty($echoed);
    }

    public function test_http_completion_log_includes_shared_request_id(): void
    {
        $requestId = 'log-request-001';

        $this->withHeader(AssignRequestId::HEADER, $requestId)
            ->getJson('/api/');

        $entry = $this->findLog(
            'info',
            'HTTP request completed.',
            static fn (array $context) => ($context['path'] ?? null) === '/api'
                && ($context['status'] ?? null) === 200,
        );

        $this->assertNotNull($entry);
        $this->assertSame($requestId, $entry['request_id']);
        $this->assertArrayNotHasKey('request_id', $entry['context']);
    }

    public function test_auth_failure_log_includes_shared_request_id(): void
    {
        $requestId = 'auth-fail-789';

        $this->withHeader(AssignRequestId::HEADER, $requestId)
            ->getJson('/api/me');

        $entry = $this->findLog(
            'warning',
            'Google Workspace authentication failed.',
            static fn (array $context) => ($context['reason'] ?? null) === 'missing_token',
        );

        $this->assertNotNull($entry);
        $this->assertSame($requestId, $entry['request_id']);
        $this->assertArrayNotHasKey('request_id', $entry['context']);
    }

    public function test_authenticated_me_logs_share_request_id_without_repeating_it(): void
    {
        $requestId = 'me-journey-42';

        $this->mock(GoogleIdTokenVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'sub' => 'google-sub-logging',
                'email' => 'logger@bloodbikes.wales',
                'email_verified' => true,
                'hd' => 'bloodbikes.wales',
                'name' => 'Log User',
            ]);
        });

        $response = $this->withHeader(AssignRequestId::HEADER, $requestId)
            ->withToken('valid-token')
            ->getJson('/api/me');

        $response->assertOk()
            ->assertHeader(AssignRequestId::HEADER, $requestId);

        foreach ([
            'Google Workspace authentication succeeded.',
            'Resolved Plasma roles for authenticated user.',
            'Served authenticated user profile.',
            'HTTP request completed.',
        ] as $message) {
            $entry = $this->findLog('info', $message, static fn (array $context): bool => true);

            $this->assertNotNull($entry, "Missing log: {$message}");
            $this->assertSame($requestId, $entry['request_id']);
            $this->assertArrayNotHasKey('request_id', $entry['context']);
        }

        $this->assertNotNull($this->findLog(
            'info',
            'Google Workspace authentication succeeded.',
            static fn (array $context) => ($context['email'] ?? null) === 'logger@bloodbikes.wales',
        ));

        $this->assertNotNull($this->findLog(
            'info',
            'Resolved Plasma roles for authenticated user.',
            static fn (array $context) => array_key_exists('roles', $context),
        ));

        $this->assertNotNull($this->findLog(
            'info',
            'HTTP request completed.',
            static fn (array $context) => ($context['status'] ?? null) === 200
                && ($context['path'] ?? null) === '/api/me',
        ));
    }

    /**
     * @param  callable(array<string, mixed>): bool  $contextMatch
     * @return array{level: string, message: string, context: array<string, mixed>, request_id: mixed}|null
     */
    private function findLog(string $level, string $message, callable $contextMatch): ?array
    {
        foreach ($this->logged as $entry) {
            if ($entry['level'] === $level
                && $entry['message'] === $message
                && $contextMatch($entry['context'])
            ) {
                return $entry;
            }
        }

        return null;
    }
}
