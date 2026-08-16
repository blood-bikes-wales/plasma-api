<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assign a request / correlation ID for the whole HTTP lifecycle.
 *
 * Accepts inbound X-Request-Id or X-Correlation-Id when well-formed; otherwise
 * generates a UUID. The ID is stored once via Context::add so every log line
 * in the request (and hydrated queue jobs) carries it as metadata, and is
 * echoed as X-Request-Id for clients.
 */
class AssignRequestId
{
    public const string ATTRIBUTE = 'request_id';

    public const string HEADER = 'X-Request-Id';

    private const string CORRELATION_HEADER = 'X-Correlation-Id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        // Once per request: Context is appended as log metadata automatically
        // (and hydrates into queued jobs). Do not pass request_id into each Log:: call.
        Context::add('request_id', $requestId);

        $startedAt = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        Log::info('HTTP request completed.', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        foreach ([self::HEADER, self::CORRELATION_HEADER] as $header) {
            $incoming = $request->headers->get($header);

            if (is_string($incoming) && $this->isValidRequestId($incoming)) {
                return $incoming;
            }
        }

        return (string) Str::uuid();
    }

    /**
     * Reject values that are too long or contain characters unsafe for logs/headers.
     */
    private function isValidRequestId(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== ''
            && strlen($trimmed) <= 128
            && preg_match('/^[A-Za-z0-9\-._]+$/', $trimmed) === 1;
    }
}
