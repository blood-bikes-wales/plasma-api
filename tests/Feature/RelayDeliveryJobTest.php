<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\Bike;
use App\Models\DeliveryJob;
use App\Models\OperationalShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelayDeliveryJobTest extends TestCase
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

    public function test_a_controller_can_convert_a_new_job_into_relay_legs(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0200']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0001',
                        'address' => 'Services, M4 Junction 32',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('isRelay', true)
            ->assertJsonPath('status', JobStatus::New->value)
            ->assertJsonPath('allowedActions', ['cancel'])
            ->assertJsonCount(2, 'legs')
            ->assertJsonPath('legs.0.reference', 'JB-0200-L1')
            ->assertJsonPath('legs.0.status', JobStatus::New->value)
            ->assertJsonPath('legs.0.legNumber', 1)
            ->assertJsonPath('legs.1.reference', 'JB-0200-L2')
            ->assertJsonPath('legs.1.legNumber', 2);

        $this->assertDatabaseHas('delivery_jobs', [
            'id' => $job->id,
            'is_relay' => true,
        ]);
        $this->assertSame(3, DeliveryJob::query()->count());
    }

    public function test_relay_legs_run_independent_lifecycle_and_aggregate_parent_status(): void
    {
        $bike = Bike::factory()->create();
        $shiftOne = OperationalShift::factory()->create([
            'rider_id' => 100001,
            'rider_name' => 'Alex Morgan',
            'bike_id' => $bike->id,
        ]);
        $shiftTwo = OperationalShift::factory()->create([
            'rider_id' => 100002,
            'rider_name' => 'Sam Taylor',
            'bike_id' => Bike::factory()->create()->id,
        ]);

        $job = DeliveryJob::factory()->create(['reference' => 'JB-0201']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [[
                    'placeId' => 'ChIJRendezvous0002',
                    'address' => 'Handoff car park',
                    'latitude' => 51.49,
                    'longitude' => -3.15,
                ]],
            ])
            ->assertOk();

        $legs = DeliveryJob::query()->where('parent_job_id', $job->id)->orderBy('leg_number')->get();
        $firstLeg = $legs[0];
        $secondLeg = $legs[1];

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$firstLeg->id}/actions/allocate", ['shiftId' => $shiftOne->id])
            ->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::New->value, $job->status->value);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$firstLeg->id}/actions/collect", [
                'contentsConfirmed' => true,
                'suitablySealed' => false,
                'receiptNumber' => 'RCP-100',
                'collectedAt' => '2026-08-29T12:00:00+01:00',
            ])
            ->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::New->value, $job->status->value);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$firstLeg->id}/actions/deliver", [
                'recipient' => 'Relay handoff',
                'deliveredAt' => '2026-08-29T12:30:00+01:00',
            ])
            ->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::New->value, $job->status->value);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$secondLeg->id}/actions/allocate", ['shiftId' => $shiftTwo->id])
            ->assertOk();

        $job->refresh();
        $this->assertSame(JobStatus::Allocated->value, $job->status->value);
    }

    public function test_relay_legs_are_hidden_from_top_level_job_lists(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0202']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [[
                    'placeId' => 'ChIJRendezvous0003',
                    'address' => 'Relay point',
                    'latitude' => 51.48,
                    'longitude' => -3.18,
                ]],
            ])
            ->assertOk();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'JB-0202')
            ->assertJsonPath('data.0.isRelay', true)
            ->assertJsonCount(2, 'data.0.legs');
    }

    public function test_only_a_new_top_level_job_can_be_converted_to_a_relay(): void
    {
        $job = DeliveryJob::factory()->allocated()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [[
                    'placeId' => 'ChIJRendezvous0004',
                    'address' => 'Relay point',
                    'latitude' => 51.48,
                    'longitude' => -3.18,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);
    }

    public function test_direct_lifecycle_actions_are_rejected_on_a_relay_parent(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0203', 'is_relay' => true]);
        $shift = OperationalShift::factory()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);
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
