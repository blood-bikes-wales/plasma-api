<?php

namespace Tests\Feature\Services\ThreeRings;

use App\Services\ThreeRings\Data\Shift;
use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsRateLimitedException;
use App\Services\ThreeRings\Exceptions\ThreeRingsRequestException;
use App\Services\ThreeRings\Exceptions\ThreeRingsUnavailableException;
use App\Services\ThreeRings\ThreeRingsClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class ThreeRingsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.name', 'Plasma');
        config()->set('services.three_rings', [
            'base_url' => 'https://www.3r.org.uk',
            'key' => 'test-key',
            'contact_email' => 'dev@bloodbikes.wales',
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'rate_limit' => ['max_attempts' => 15, 'decay_seconds' => 60],
            'cache' => [
                'volunteers' => ['fresh' => 3600, 'stale' => 86400],
                'shifts' => ['fresh' => 300, 'stale' => 3600],
            ],
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_sends_api_key_authorization_header_and_user_agent(): void
    {
        $this->fakeThreeRings();

        $this->client()->volunteers();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'APIKEY test-key')
                && $request->hasHeader('User-Agent', 'Plasma (dev@bloodbikes.wales)');
        });
    }

    public function test_fetches_and_maps_volunteers(): void
    {
        $this->fakeThreeRings();

        $volunteers = $this->client()->volunteers();

        $this->assertCount(2, $volunteers);
        $this->assertContainsOnlyInstancesOf(Volunteer::class, $volunteers);

        $this->assertSame(100001, $volunteers[0]->id);
        $this->assertSame('Alex Morgan', $volunteers[0]->name);
        $this->assertSame('alex.morgan@example.com', $volunteers[0]->email);
        $this->assertSame(['Rider - Milk', 'Controller'], $volunteers[0]->roles);

        $this->assertSame(100002, $volunteers[1]->id);
        $this->assertNull($volunteers[1]->email);
        $this->assertSame(['Driver'], $volunteers[1]->roles);
    }

    public function test_filters_volunteers_by_role_sends_volunteer_filter_param(): void
    {
        $this->fakeThreeRings();

        $this->client()->volunteers('Rider');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/directory.json')
                && str_contains($request->url(), 'volunteer_filter=Rider');
        });
    }

    public function test_fetches_shifts_with_date_range_params(): void
    {
        $this->fakeThreeRings();

        $shifts = $this->client()->shifts(
            CarbonImmutable::parse('2026-07-24'),
            CarbonImmutable::parse('2026-07-25'),
        );

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/shift.json')
                && str_contains($request->url(), 'start_date=2026-07-24')
                && str_contains($request->url(), 'end_date=2026-07-25');
        });

        $this->assertCount(2, $shifts);
        $this->assertContainsOnlyInstancesOf(Shift::class, $shifts);
        $this->assertSame('Night Cover', $shifts[0]->title);
        $this->assertSame('North Wales', $shifts[0]->rota);
        $this->assertSame('2026-07-24T19:00:00+00:00', $shifts[0]->startsAt->toIso8601String());
        $this->assertSame('2026-07-25T07:00:00+00:00', $shifts[0]->endsAt?->toIso8601String());
        $this->assertSame([100001, 100002], $shifts[0]->volunteerIds);
        $this->assertSame(['Alex Morgan', 'Bethan Hughes'], $shifts[0]->volunteerNames);
    }

    public function test_second_call_is_served_from_cache(): void
    {
        $this->fakeThreeRings();
        $client = $this->client();

        $first = $client->volunteers();
        $second = $client->volunteers();

        Http::assertSentCount(1);
        $this->assertEquals($first, $second);
    }

    public function test_role_filtered_volunteers_cached_separately_from_unfiltered(): void
    {
        $this->fakeThreeRings();
        $client = $this->client();

        $client->volunteers();
        $client->volunteers('Rider');

        Http::assertSentCount(2);

        $client->volunteers();
        $client->volunteers('Rider');

        Http::assertSentCount(2);
    }

    public function test_rate_limit_exhaustion_without_cache_throws_rate_limited_exception(): void
    {
        Http::fake();
        $this->exhaustRateLimiter();

        try {
            $this->client()->volunteers();
            $this->fail('Expected '.ThreeRingsRateLimitedException::class.' to be thrown.');
        } catch (ThreeRingsRateLimitedException $exception) {
            $this->assertGreaterThan(0, $exception->retryAfterSeconds());
        }

        Http::assertNothingSent();
    }

    public function test_rate_limit_exhaustion_serves_stale_cache_when_available(): void
    {
        $this->fakeThreeRings();
        $client = $this->client();

        $client->volunteers();

        $this->travel(2)->hours();
        $this->exhaustRateLimiter();

        $volunteers = $client->volunteers();

        Http::assertSentCount(1);
        $this->assertCount(2, $volunteers);
    }

    public function test_server_error_serves_stale_cache_and_logs_warning(): void
    {
        Log::spy();
        $this->fakeFirstSuccessThen(fn () => Http::response([], 500));
        $client = $this->client();

        $client->volunteers();

        $this->travel(2)->hours();

        $volunteers = $client->volunteers();

        $this->assertCount(2, $volunteers);
        Http::assertSentCount(3); // 1 success + 2 failed retry attempts.
        Log::shouldHaveReceived('warning')
            ->with('Three Rings request failed, retrying.', Mockery::type('array'))
            ->twice();
        Log::shouldHaveReceived('warning')
            ->with('Three Rings unavailable, serving stale cache.', Mockery::type('array'))
            ->once();
    }

    public function test_connection_failure_serves_stale_cache(): void
    {
        Log::spy();
        $this->fakeFirstSuccessThen(fn () => throw new ConnectionException('Connection refused'));
        $client = $this->client();

        $client->volunteers();

        $this->travel(2)->hours();

        $volunteers = $client->volunteers();

        $this->assertCount(2, $volunteers);
        Log::shouldHaveReceived('warning')
            ->with('Three Rings request failed, retrying.', Mockery::type('array'))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('Three Rings unavailable, serving stale cache.', Mockery::type('array'))
            ->once();
    }

    public function test_outage_without_cache_throws_unavailable_and_logs_error(): void
    {
        Log::spy();
        Http::fake(['www.3r.org.uk/*' => Http::response([], 500)]);

        try {
            $this->client()->volunteers();
            $this->fail('Expected '.ThreeRingsUnavailableException::class.' to be thrown.');
        } catch (ThreeRingsUnavailableException $exception) {
            $this->assertNotNull($exception->getPrevious());
        }

        Log::shouldHaveReceived('error')->once();
    }

    public function test_client_error_throws_request_exception_without_stale_fallback(): void
    {
        $this->fakeFirstSuccessThen(fn () => Http::response([], 401));
        $client = $this->client();

        $client->volunteers();

        $this->travel(2)->hours();

        $this->expectException(ThreeRingsRequestException::class);

        $client->volunteers();
    }

    public function test_only_get_requests_are_ever_sent(): void
    {
        $this->fakeThreeRings();
        $client = $this->client();

        $client->volunteers();
        $client->shifts(CarbonImmutable::parse('2026-07-24'), CarbonImmutable::parse('2026-07-25'));

        Http::assertSentCount(2);

        $this->assertTrue(
            collect(Http::recorded())->every(fn (array $pair): bool => $pair[0]->method() === 'GET'),
            'The Three Rings client must only ever issue GET requests (BR-005).',
        );
    }

    private function client(): ThreeRingsClient
    {
        return $this->app->make(ThreeRingsClient::class);
    }

    private function fakeThreeRings(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response($this->fixture('directory')),
            'www.3r.org.uk/shift.json*' => Http::response($this->fixture('shifts')),
        ]);
    }

    /**
     * Fake a first successful directory response followed by the given
     * failure for every subsequent request.
     */
    private function fakeFirstSuccessThen(callable $failure): void
    {
        $requests = 0;

        Http::fake(function () use (&$requests, $failure) {
            $requests++;

            return $requests === 1
                ? Http::response($this->fixture('directory'))
                : $failure();
        });
    }

    private function exhaustRateLimiter(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        for ($i = 0; $i < 15; $i++) {
            $limiter->hit('three-rings-api', 60);
        }
    }

    /**
     * @return array<mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/ThreeRings/{$name}.json"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
