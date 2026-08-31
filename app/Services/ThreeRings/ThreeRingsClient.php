<?php

namespace App\Services\ThreeRings;

use App\Services\ThreeRings\Data\Shift;
use App\Services\ThreeRings\Data\Volunteer;
use App\Services\ThreeRings\Exceptions\ThreeRingsRateLimitedException;
use App\Services\ThreeRings\Exceptions\ThreeRingsRequestException;
use App\Services\ThreeRings\Exceptions\ThreeRingsUnavailableException;
use DateTimeInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only client for the Three Rings API (https://www.3r.org.uk).
 *
 * Three Rings is the external system of record for volunteers, roles and
 * shifts; this client never writes back (BR-005) and only ever issues GET
 * requests. Responses are cached in two tiers: a "fresh" copy served without
 * contacting Three Rings, and a longer-lived "stale" copy served only when
 * Three Rings is unavailable or the local request budget is exhausted
 * (BR-008).
 */
final class ThreeRingsClient
{
    private const string RATE_LIMITER_KEY = 'three-rings-api';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly RateLimiter $limiter,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
        private readonly ThreeRingsConfig $config,
    ) {}

    /**
     * @return Collection<int, Volunteer>
     */
    public function volunteers(?string $roleFilter = null): Collection
    {
        $query = $roleFilter !== null ? ['volunteer_filter' => $roleFilter] : [];

        $data = $this->get('/directory.json', $query, 'volunteers');

        return collect($data['volunteers'] ?? [])->map(Volunteer::fromArray(...))->values();
    }

    /**
     * @return Collection<int, Shift>
     */
    public function shifts(DateTimeInterface $start, DateTimeInterface $end): Collection
    {
        $query = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];

        $data = $this->get('/shift.json', $query, 'shifts');

        return collect($data['shifts'] ?? [])->map(Shift::fromArray(...))->values();
    }

    /**
     * Perform a cached, rate-limited GET request. This is the only method
     * that touches HTTP, and the verb is hard-coded to GET (BR-005).
     *
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query, string $endpoint): array
    {
        $freshKey = $this->cacheKey('fresh', $path, $query);
        $staleKey = $this->cacheKey('stale', $path, $query);

        $fresh = $this->cache->get($freshKey);

        if ($fresh !== null) {
            $this->logger->debug('Three Rings cache hit (fresh).', $this->requestContext($path, $query));

            return $fresh;
        }

        if ($this->limiter->tooManyAttempts(self::RATE_LIMITER_KEY, $this->config->maxAttempts)) {
            $stale = $this->cache->get($staleKey);

            if ($stale !== null) {
                $this->logger->info('Three Rings request budget exhausted, serving stale cache.', $this->requestContext($path, $query));

                return $stale;
            }

            $retryAfter = $this->limiter->availableIn(self::RATE_LIMITER_KEY);

            $this->logger->error('Three Rings request budget exhausted with no cached data.', [
                ...$this->requestContext($path, $query),
                'retry_after_seconds' => $retryAfter,
            ]);

            throw ThreeRingsRateLimitedException::retryAfter($retryAfter);
        }

        try {
            $response = $this->send($path, $query);
        } catch (ConnectionException $exception) {
            return $this->staleOrFail($staleKey, $path, $query, $exception);
        } catch (RequestException $exception) {
            if ($exception->response->serverError()) {
                return $this->staleOrFail($staleKey, $path, $query, $exception);
            }

            $this->logger->error('Three Rings rejected the request.', [
                ...$this->requestContext($path, $query),
                'status' => $exception->response->status(),
                'exception' => $exception,
            ]);

            throw ThreeRingsRequestException::forPath($path, $exception->response->status(), $exception);
        }

        $data = (array) $response->json();

        $this->cache->put($freshKey, $data, $this->config->freshTtl($endpoint));
        $this->cache->put($staleKey, $data, $this->config->staleTtl($endpoint));

        return $data;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function send(string $path, array $query): Response
    {
        $this->logger->info('Three Rings request started.', $this->requestContext($path, $query));

        $this->limiter->hit(self::RATE_LIMITER_KEY, $this->config->decaySeconds);

        $response = $this->pending()
            ->retry(2, 500, function (Throwable $exception) use ($path, $query): bool {
                if (! $this->isTransientFailure($exception)) {
                    return false;
                }

                // Each retry is a real request against the Three Rings budget.
                if ($this->limiter->tooManyAttempts(self::RATE_LIMITER_KEY, $this->config->maxAttempts)) {
                    $this->logger->warning('Three Rings request failed and retry budget exhausted.', [
                        ...$this->requestContext($path, $query),
                        'exception' => $exception,
                    ]);

                    return false;
                }

                $this->logger->warning('Three Rings request failed, retrying.', [
                    ...$this->requestContext($path, $query),
                    'exception' => $exception,
                ]);

                $this->limiter->hit(self::RATE_LIMITER_KEY, $this->config->decaySeconds);

                return true;
            })
            ->get($path, $query);

        $this->logger->info('Three Rings request succeeded.', [
            ...$this->requestContext($path, $query),
            'status' => $response->status(),
        ]);

        return $response;
    }

    private function pending(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->config->baseUrl)
            ->timeout($this->config->timeoutSeconds)
            ->connectTimeout($this->config->connectTimeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'APIKEY '.$this->config->apiKey,
                'User-Agent' => $this->config->userAgent,
            ]);
    }

    private function isTransientFailure(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }

    /**
     * Serve the stale cache copy during an outage, or fail the read when no
     * sufficiently recent copy exists.
     *
     * @param  array<string, string>  $query
     * @return array<mixed>
     */
    private function staleOrFail(string $staleKey, string $path, array $query, Throwable $exception): array
    {
        $stale = $this->cache->get($staleKey);

        if ($stale !== null) {
            $this->logger->warning('Three Rings unavailable, serving stale cache.', [
                ...$this->requestContext($path, $query),
                'exception' => $exception,
            ]);

            return $stale;
        }

        $this->logger->error('Three Rings unavailable and no cached data exists.', [
            ...$this->requestContext($path, $query),
            'exception' => $exception,
        ]);

        throw ThreeRingsUnavailableException::forPath($path, $exception);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{path: string, query: array<string, string>}
     */
    private function requestContext(string $path, array $query): array
    {
        return [
            'path' => $path,
            'query' => $query,
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function cacheKey(string $tier, string $path, array $query): string
    {
        return sprintf('three-rings:%s:%s:%s', $tier, $path, md5((string) json_encode($query)));
    }
}
