<?php

namespace App\Services\Auth;

use App\Contracts\GoogleIdTokenVerifier;
use App\Exceptions\Auth\InvalidWorkspaceTokenException;
use Google\Client;
use Throwable;

class GoogleApiClientIdTokenVerifier implements GoogleIdTokenVerifier
{
    public function __construct(private Client $client) {}

    /**
     * Verify a Google-issued ID token and return its claims.
     *
     * The underlying Google client validates the token signature against
     * Google's published certificates, the issuer, the expiry, and that the
     * "aud" claim matches the configured OAuth client ID.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWorkspaceTokenException
     */
    public function verify(string $idToken): array
    {
        try {
            $claims = $this->client->verifyIdToken($idToken);
        } catch (Throwable $exception) {
            throw new InvalidWorkspaceTokenException(
                'The Google ID token could not be verified.',
                previous: $exception,
            );
        }

        if ($claims === false) {
            throw new InvalidWorkspaceTokenException('The Google ID token could not be verified.');
        }

        return $claims;
    }
}
