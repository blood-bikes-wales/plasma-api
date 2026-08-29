<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateDeliveryJobTest extends TestCase
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
        $this->postJson('/api/jobs', [])->assertUnauthorized();
    }

    public function test_a_rider_cannot_create_a_job(): void
    {
        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $this->validPayload())
            ->assertForbidden();
    }

    public function test_a_controller_creates_a_job_in_the_new_state(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')
            ->postJson('/api/jobs', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('status', JobStatus::New->value)
            ->assertJsonPath('reference', 'JB-0001')
            ->assertJsonPath('sender.name', 'Dr Patel')
            ->assertJsonPath('sender.phone', '029 2074 7747')
            ->assertJsonPath('sender.organisation', 'University Hospital of Wales')
            ->assertJsonPath('collection.placeId', 'ChIJCollectionPlaceId0001')
            ->assertJsonPath('collection.address', 'University Hospital of Wales, Cardiff CF14 4XW')
            ->assertJsonPath('collection.latitude', 51.5068)
            ->assertJsonPath('delivery.placeId', 'ChIJDeliveryPlaceId0001')
            ->assertJsonPath('contents', 'Blood samples')
            ->assertJsonPath('serviceAreas', ['South'])
            ->assertJsonMissingPath('data');

        $this->assertDatabaseHas('delivery_jobs', [
            'reference' => 'JB-0001',
            'status' => JobStatus::New->value,
            'sender_name' => 'Dr Patel',
            'collection_place_id' => 'ChIJCollectionPlaceId0001',
        ]);
        $this->assertSame(1, DeliveryJob::query()->count());
    }

    public function test_an_admin_can_create_a_job_without_a_controller_role(): void
    {
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('status', JobStatus::New->value);
    }

    public function test_locations_missing_a_place_id_are_rejected(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        unset($payload['collection']['placeId']);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['collection.placeId'])
            ->assertJson([
                'errors' => [
                    'collection.placeId' => [
                        'Collection location must include a Google Place ID.',
                    ],
                ],
            ]);
    }

    public function test_locations_missing_coordinates_are_rejected(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        unset($payload['delivery']['latitude'], $payload['delivery']['longitude']);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery.latitude', 'delivery.longitude']);
    }

    public function test_locations_missing_an_address_are_rejected(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        unset($payload['collection']['address']);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['collection.address']);
    }

    public function test_snake_case_location_aliases_are_accepted(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['collection'] = [
            'place_id' => 'ChIJCollectionPlaceId0001',
            'address' => 'University Hospital of Wales, Cardiff CF14 4XW',
            'lat' => 51.5068,
            'lng' => -3.19,
        ];
        $payload['service_areas'] = ['South'];
        unset($payload['serviceAreas']);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('collection.placeId', 'ChIJCollectionPlaceId0001');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'sender' => [
                'name' => 'Dr Patel',
                'phone' => '029 2074 7747',
                'organisation' => 'University Hospital of Wales',
            ],
            'collection' => [
                'placeId' => 'ChIJCollectionPlaceId0001',
                'address' => 'University Hospital of Wales, Cardiff CF14 4XW',
                'latitude' => 51.5068,
                'longitude' => -3.19,
            ],
            'delivery' => [
                'placeId' => 'ChIJDeliveryPlaceId0001',
                'address' => 'Royal Glamorgan Hospital, Llantrisant CF72 8XR',
                'latitude' => 51.547,
                'longitude' => -3.391,
            ],
            'contents' => 'Blood samples',
            'serviceAreas' => ['South'],
        ];
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
