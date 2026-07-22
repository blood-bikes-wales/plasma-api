<?php

namespace App\Contracts;

use App\Exceptions\Auth\InvalidWorkspaceTokenException;

interface GoogleIdTokenVerifier
{
    /**
     * Verify a Google-issued ID token and return its claims.
     *
     * Implementations must validate the token signature, issuer, expiry and
     * that the "aud" claim matches the configured OAuth client ID.
     *
     * @return array<string, mixed> The verified token claims (payload).
     *
     * @throws InvalidWorkspaceTokenException When the token is missing, malformed, expired or fails verification.
     */
    public function verify(string $idToken): array;
}
