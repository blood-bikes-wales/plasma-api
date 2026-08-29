<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListDeliveryJobsTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/jobs/active')->assertUnauthorized();
        $this->getJson('/api/jobs/completed')->assertUnauthorized();
    }

    public function test_a_rider_can_list_jobs(): void
    {
        DeliveryJob::factory()->create(['reference' => 'JB-1042']);

        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1042')
            ->assertJsonPath('data.0.status', JobStatus::New->value);
    }

    public function test_active_scope_returns_in_progress_jobs_newest_first(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1001']);
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1002']);
        DeliveryJob::factory()->create(['reference' => 'JB-1040']);

        Carbon::setTestNow('2026-08-29 10:00:01');
        DeliveryJob::factory()->allocated()->create(['reference' => 'JB-1041']);

        Carbon::setTestNow('2026-08-29 10:00:02');
        DeliveryJob::factory()->collected()->create(['reference' => 'JB-1042']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.reference', 'JB-1042')
            ->assertJsonPath('data.0.status', JobStatus::Collected->value)
            ->assertJsonPath('data.1.reference', 'JB-1041')
            ->assertJsonPath('data.2.reference', 'JB-1040');
    }

    public function test_completed_scope_returns_delivered_and_cancelled_jobs(): void
    {
        DeliveryJob::factory()->create(['reference' => 'JB-1040']);
        DeliveryJob::factory()->allocated()->create(['reference' => 'JB-1041']);

        Carbon::setTestNow('2026-08-29 11:00:00');
        DeliveryJob::factory()->delivered()->create(['reference' => 'JB-1030']);

        Carbon::setTestNow('2026-08-29 11:00:01');
        DeliveryJob::factory()->cancelled()->create(['reference' => 'JB-1028']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/completed')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'JB-1028')
            ->assertJsonPath('data.0.status', JobStatus::Cancelled->value)
            ->assertJsonPath('data.1.reference', 'JB-1030')
            ->assertJsonPath('data.1.status', JobStatus::Delivered->value);
    }

    public function test_an_empty_scope_returns_an_empty_list(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_an_unknown_scope_is_not_found(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/all')
            ->assertNotFound();
    }

    public function test_an_admin_can_list_jobs_without_a_controller_role(): void
    {
        DeliveryJob::factory()->create(['reference' => 'JB-1042']);

        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1042');
    }

    /**
     * @return array<string, mixed>
     */
    private function volunteer(string $roleName): array
    {
        return [
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => $roleName]],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        $list = [$volunteers];
        if (array_is_list($volunteers)) {
            $list = $volunteers;
        }

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => $list]),
        ]);
    }

    /**
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
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
