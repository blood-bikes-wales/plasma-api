<?php

namespace App\Http\Middleware;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\AuthFailureReason;
use App\Exceptions\Auth\InvalidWorkspaceTokenException;
use App\Models\AuthAuditLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGoogleWorkspace
{
    public function __construct(private GoogleIdTokenVerifier $verifier) {}

    /**
     * Authenticate the request using a Google Workspace ID token.
     *
     * Expects a Bearer token containing a Google-issued ID token. The token
     * must verify against the configured OAuth client ID, belong to a
     * verified email address, and originate from the allowed Workspace
     * domain. Failures are recorded in the auth audit log.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypassAuthentication()) {
            $user = $this->resolveLocalDevelopmentUser();
            Auth::setUser($user);
            $this->shareUserContext($user);

            return $next($request);
        }

        $idToken = $request->bearerToken();

        if ($idToken === null) {
            return $this->deny($request, AuthFailureReason::MissingToken);
        }

        try {
            $claims = $this->verifier->verify($idToken);
        } catch (InvalidWorkspaceTokenException) {
            return $this->deny($request, AuthFailureReason::InvalidToken);
        }

        $email = $claims['email'] ?? null;
        $hostedDomain = $claims['hd'] ?? null;

        if (! ($claims['email_verified'] ?? false)) {
            return $this->deny($request, AuthFailureReason::EmailNotVerified, $email, $hostedDomain);
        }

        if (! $this->belongsToAllowedDomain($email, $hostedDomain)) {
            return $this->deny($request, AuthFailureReason::OutOfDomain, $email, $hostedDomain);
        }

        $user = $this->provisionUser($claims);
        Auth::setUser($user);
        $this->shareUserContext($user);

        Log::channel('auth')->info('Google Workspace authentication succeeded.', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return $next($request);
    }

    /**
     * Determine whether the local-only authentication bypass is active.
     *
     * The bypass is only honoured in the "local" and "testing" environments;
     * the flag can never take effect in deployed environments.
     */
    private function shouldBypassAuthentication(): bool
    {
        return config('auth.disabled') && app()->environment('local', 'testing');
    }

    /**
     * Resolve the user impersonated when authentication is disabled locally.
     */
    private function resolveLocalDevelopmentUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'developer@'.config('services.google.allowed_domain')],
            ['name' => 'Local Developer'],
        );
    }

    /**
     * Determine whether the token belongs to the allowed Workspace domain.
     *
     * Checks both the "hd" (hosted domain) claim, which Google only sets for
     * Workspace accounts, and the email domain as defence in depth.
     */
    private function belongsToAllowedDomain(?string $email, ?string $hostedDomain): bool
    {
        $allowedDomain = config('services.google.allowed_domain');

        return $hostedDomain === $allowedDomain
            && is_string($email)
            && Str::endsWith(Str::lower($email), '@'.Str::lower($allowedDomain));
    }

    /**
     * Find or create the user matching the verified token claims.
     *
     * Users are matched by Google ID first, falling back to email so that
     * pre-provisioned accounts are linked on their first Google sign-in.
     *
     * @param  array<string, mixed>  $claims
     */
    private function provisionUser(array $claims): User
    {
        $user = User::query()
            ->where('google_id', $claims['sub'])
            ->orWhere('email', $claims['email'])
            ->first() ?? new User;

        $user->forceFill([
            'google_id' => $claims['sub'],
            'email' => $claims['email'],
            'name' => $claims['name'] ?? $user->name ?? Str::before($claims['email'], '@'),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * Record the authentication failure and produce the JSON denial response.
     */
    private function deny(
        Request $request,
        AuthFailureReason $reason,
        ?string $email = null,
        ?string $hostedDomain = null,
    ): Response {
        AuthAuditLog::create([
            'email' => $email,
            'hosted_domain' => $hostedDomain,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Log::channel('auth')->warning('Google Workspace authentication failed.', [
            'reason' => $reason->value,
            'email' => $email,
            'hosted_domain' => $hostedDomain,
            'ip_address' => $request->ip(),
        ]);

        return match ($reason) {
            AuthFailureReason::MissingToken,
            AuthFailureReason::InvalidToken => response()->json(['message' => 'Unauthenticated.'], 401),
            AuthFailureReason::EmailNotVerified,
            AuthFailureReason::OutOfDomain => response()->json(['message' => 'Forbidden.'], 403),
        };
    }

    /**
     * Attach authenticated identity to shared log / context (never the token).
     */
    private function shareUserContext(User $user): void
    {
        $context = [
            'user_id' => $user->id,
            'email' => $user->email,
            'google_id' => $user->google_id,
            'is_admin' => (bool) $user->is_admin,
        ];

        Context::add('user_id', $user->id);
        Context::add('email', $user->email);
        Context::add('google_id', $user->google_id);
        Context::add('is_admin', (bool) $user->is_admin);
        Log::shareContext($context);
    }
}
