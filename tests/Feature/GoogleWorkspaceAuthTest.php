<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\AuthFailureReason;
use App\Exceptions\Auth\InvalidWorkspaceTokenException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleWorkspaceAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'www.3r.org.uk/*' => Http::response(['volunteers' => []]),
        ]);
    }

    public function test_request_without_token_is_denied_and_audited(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertUnauthorized();
        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::MissingToken->value,
        ]);
    }

    public function test_request_with_invalid_token_is_denied_and_audited(): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('invalid-token')
                ->andThrow(new InvalidWorkspaceTokenException('The Google ID token could not be verified.'));
        });

        $response = $this->withToken('invalid-token')->getJson('/api/me');

        $response->assertUnauthorized();
        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::InvalidToken->value,
        ]);
    }

    public function test_request_with_unverified_email_is_denied_and_audited(): void
    {
        $this->mockVerifiedClaims(['email_verified' => false]);

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::EmailNotVerified->value,
            'email' => 'rider@bloodbikes.wales',
        ]);
    }

    public function test_request_from_another_workspace_domain_is_denied_and_audited(): void
    {
        $this->mockVerifiedClaims([
            'email' => 'intruder@example.com',
            'hd' => 'example.com',
        ]);

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::OutOfDomain->value,
            'email' => 'intruder@example.com',
            'hosted_domain' => 'example.com',
        ]);
    }

    public function test_request_from_consumer_google_account_without_hosted_domain_is_denied(): void
    {
        $this->mockVerifiedClaims([
            'email' => 'someone@gmail.com',
            'hd' => null,
        ]);

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertForbidden();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::OutOfDomain->value,
        ]);
    }

    public function test_request_with_matching_hosted_domain_but_foreign_email_domain_is_denied(): void
    {
        $this->mockVerifiedClaims([
            'email' => 'spoof@evil.com',
        ]);

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'reason' => AuthFailureReason::OutOfDomain->value,
            'email' => 'spoof@evil.com',
        ]);
    }

    public function test_email_domain_comparison_is_case_insensitive(): void
    {
        $this->mockVerifiedClaims([
            'email' => 'Rider@BloodBikes.Wales',
        ]);

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_valid_token_provisions_a_new_user_and_authenticates(): void
    {
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk()->assertJsonFragment(['email' => 'rider@bloodbikes.wales']);
        $this->assertDatabaseHas('users', [
            'google_id' => 'google-sub-123',
            'email' => 'rider@bloodbikes.wales',
            'name' => 'Test Rider',
        ]);
        $this->assertAuthenticatedAs(User::firstWhere('google_id', 'google-sub-123'));
        $this->assertDatabaseCount('auth_audit_logs', 0);
    }

    public function test_valid_token_links_google_id_to_existing_user_matched_by_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('google-sub-123', $existingUser->fresh()->google_id);
    }

    public function test_valid_token_matches_existing_user_by_google_id(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'old-address@bloodbikes.wales',
            'google_id' => 'google-sub-123',
        ]);

        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/me');

        $response->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('rider@bloodbikes.wales', $existingUser->fresh()->email);
    }

    public function test_auth_disabled_bypasses_verification_in_testing_environment(): void
    {
        config(['auth.disabled' => true]);

        $response = $this->getJson('/api/me');

        $response->assertOk()->assertJsonFragment(['email' => 'developer@bloodbikes.wales']);
        $this->assertAuthenticated();
    }

    public function test_auth_disabled_is_ignored_outside_local_and_testing_environments(): void
    {
        config(['auth.disabled' => true]);
        $this->app['env'] = 'production';

        $response = $this->getJson('/api/me');

        $response->assertUnauthorized();
        $this->assertGuest();
    }

    /**
     * Bind a verifier mock returning valid Workspace claims with overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function mockVerifiedClaims(array $overrides = []): void
    {
        $claims = array_filter(array_merge([
            'sub' => 'google-sub-123',
            'email' => 'rider@bloodbikes.wales',
            'email_verified' => true,
            'hd' => 'bloodbikes.wales',
            'name' => 'Test Rider',
        ], $overrides), fn ($value) => $value !== null);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
