<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsServiceAreas;
use Tests\TestCase;

class CreateDeliveryJobTest extends TestCase
{
    use RefreshDatabase;
    use SeedsServiceAreas;

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
        $this->seedServiceAreas();
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
            ->assertJsonPath('serviceAreas', ['E'])
            ->assertJsonMissingPath('data');

        $this->assertDatabaseHas('places', [
            'external_id' => 'ChIJCollectionPlaceId0001',
        ]);
        $this->assertDatabaseHas('delivery_jobs', [
            'reference' => 'JB-0001',
            'status' => JobStatus::New->value,
            'sender_name' => 'Dr Patel',
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
        $payload['service_areas'] = ['E'];
        unset($payload['serviceAreas']);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('collection.placeId', 'ChIJCollectionPlaceId0001');
    }

    public function test_notes_are_stored_on_the_job_and_not_copied_to_the_next_job_at_the_same_place(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['collection']['notes'] = '  path lab  ';

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('collection.notes', 'path lab')
            ->assertJsonPath('delivery.notes', null);

        $this->assertDatabaseHas('delivery_jobs', [
            'reference' => 'JB-0001',
            'collection_notes' => 'path lab',
            'delivery_notes' => null,
        ]);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('reference', 'JB-0002')
            ->assertJsonPath('collection.placeId', 'ChIJCollectionPlaceId0001')
            ->assertJsonPath('collection.notes', null)
            ->assertJsonPath('delivery.notes', null);

        $this->assertSame(1, Place::query()->where('external_id', 'ChIJCollectionPlaceId0001')->count());
    }

    public function test_blank_notes_are_stored_as_null(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['collection']['notes'] = '   ';
        $payload['delivery']['notes'] = '';

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('collection.notes', null)
            ->assertJsonPath('delivery.notes', null);
    }

    public function test_notes_longer_than_500_characters_are_rejected(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['collection']['notes'] = str_repeat('a', 501);
        $payload['delivery']['notes'] = str_repeat('b', 501);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['collection.notes', 'delivery.notes']);
    }

    public function test_notes_of_exactly_500_characters_are_accepted(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['delivery']['notes'] = str_repeat('b', 500);

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('delivery.notes', str_repeat('b', 500));
    }

    public function test_a_job_with_one_estimate_stores_that_estimate_and_leaves_the_other_null(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['estimatedCollectionAt'] = '2026-11-20T09:30:00.000Z';

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('estimatedCollectionAt', '2026-11-20T09:30:00+00:00')
            ->assertJsonPath('estimatedDeliveryAt', null);

        $job = DeliveryJob::query()->where('reference', 'JB-0001')->firstOrFail();
        $this->assertSame('2026-11-20 09:30:00', $job->estimated_collection_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($job->estimated_delivery_at);
        $this->assertNull($job->collected_at);
        $this->assertNull($job->delivered_at);
    }

    public function test_a_job_with_both_estimates_stores_both_in_utc(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['estimatedCollectionAt'] = '2026-06-20T10:30:00+01:00';
        $payload['estimatedDeliveryAt'] = '2026-06-20T12:00:00+01:00';

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('estimatedCollectionAt', '2026-06-20T09:30:00+00:00')
            ->assertJsonPath('estimatedDeliveryAt', '2026-06-20T11:00:00+00:00');
    }

    public function test_a_job_with_no_estimates_leaves_both_columns_null(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('estimatedCollectionAt', null)
            ->assertJsonPath('estimatedDeliveryAt', null);

        $this->assertDatabaseHas('delivery_jobs', [
            'reference' => 'JB-0001',
            'estimated_collection_at' => null,
            'estimated_delivery_at' => null,
        ]);
    }

    public function test_blank_or_null_estimates_are_stored_as_null(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['estimatedCollectionAt'] = '';
        $payload['estimatedDeliveryAt'] = null;

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('estimatedCollectionAt', null)
            ->assertJsonPath('estimatedDeliveryAt', null);
    }

    public function test_an_invalid_estimate_is_rejected(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['estimatedCollectionAt'] = 'half past nine';
        $payload['estimatedDeliveryAt'] = ['2026-11-20T09:30:00Z'];

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estimatedCollectionAt', 'estimatedDeliveryAt']);

        $this->assertSame(0, DeliveryJob::query()->count());
    }

    #[DataProvider('validPhoneProvider')]
    public function test_valid_uk_phone_numbers_are_accepted_as_typed(string $phone): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['sender']['phone'] = $phone;

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertCreated()
            ->assertJsonPath('sender.phone', trim($phone));
    }

    #[DataProvider('invalidPhoneProvider')]
    public function test_invalid_phone_numbers_are_rejected(?string $phone): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['sender']['phone'] = $phone;

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sender.phone']);

        $this->assertSame(0, DeliveryJob::query()->count());
    }

    public function test_an_invalid_phone_number_gets_the_uk_phone_message(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $this->validPayload();
        $payload['sender']['phone'] = 'abc';

        $this->withToken('valid-token')
            ->postJson('/api/jobs', $payload)
            ->assertUnprocessable()
            ->assertJson([
                'errors' => [
                    'sender.phone' => ['Enter a valid UK phone number, for example 029 2074 7747'],
                ],
            ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPhoneProvider(): array
    {
        return [
            'landline, no spaces' => ['02920747747'],
            'landline, spaces' => ['029 2074 7747'],
            'landline, +44' => ['+44 29 2074 7747'],
            'mobile' => ['07700 900101'],
            'brackets and hyphens' => ['(029) 2074-7747'],
            'ten digits' => ['0800 123 456'],
        ];
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidPhoneProvider(): array
    {
        return [
            'letters' => ['abc'],
            'letters in number' => ['029 2074 77AB'],
            'too short' => ['123'],
            'too long' => ['0292074774712345'],
            'nine digits' => ['029207477'],
            'twelve digits' => ['029207477471'],
            'no leading zero' => ['2920747747'],
            'international' => ['+1 202 555 0143'],
            'empty' => [''],
            'missing' => [null],
        ];
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
            'serviceAreas' => ['E'],
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
