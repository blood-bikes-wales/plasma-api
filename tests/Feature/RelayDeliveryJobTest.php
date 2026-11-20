<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobScope;
use App\Enums\JobStatus;
use App\Http\Resources\DeliveryJobResource;
use App\Models\Bike;
use App\Models\DeliveryJob;
use App\Models\OperationalShift;
use App\Models\Rider;
use App\Models\User;
use App\Services\Jobs\DeliveryJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $job = DeliveryJob::factory()->create([
            'reference' => 'JB-0200',
            'collection_notes' => 'path lab',
            'delivery_notes' => 'reception outside',
            'estimated_collection_at' => '2026-11-20 09:30:00',
            'estimated_delivery_at' => '2026-11-20 11:00:00',
        ]);

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
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
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
            ->assertJsonPath('legs.1.legNumber', 2)
            ->assertJsonPath('legs.0.collection.notes', null)
            ->assertJsonPath('legs.0.delivery.notes', null)
            ->assertJsonPath('legs.1.collection.notes', null)
            ->assertJsonPath('legs.1.delivery.notes', null)
            ->assertJsonPath('estimatedCollectionAt', '2026-11-20T09:30:00+00:00')
            ->assertJsonPath('estimatedDeliveryAt', '2026-11-20T11:00:00+00:00')
            ->assertJsonPath('legs.0.estimatedCollectionAt', null)
            ->assertJsonPath('legs.0.estimatedDeliveryAt', null)
            ->assertJsonPath('legs.1.estimatedCollectionAt', null)
            ->assertJsonPath('legs.1.estimatedDeliveryAt', null);

        $this->assertTrue($job->fresh()->isRelayParent());
        $this->assertSame(3, DeliveryJob::query()->count());
    }

    public function test_relay_legs_inherit_the_job_type_and_litres_of_a_milk_job(): void
    {
        $job = DeliveryJob::factory()->milk(2.5)->create(['reference' => 'JB-0205']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0005',
                        'address' => 'Services, M4 Junction 32',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('jobType', 'milk')
            ->assertJsonPath('milkLitres', 2.5)
            ->assertJsonCount(2, 'legs')
            ->assertJsonPath('legs.0.jobType', 'milk')
            ->assertJsonPath('legs.0.milkLitres', 2.5)
            ->assertJsonPath('legs.1.jobType', 'milk')
            ->assertJsonPath('legs.1.milkLitres', 2.5);

        $this->assertSame(2, DeliveryJob::query()
            ->where('parent_job_id', $job->id)
            ->where('job_type', 'milk')
            ->where('milk_litres', 2.5)
            ->count());
    }

    public function test_relay_legs_inherit_the_mothers_contact_of_a_milk_job(): void
    {
        $job = DeliveryJob::factory()->milk(2.5)->create(['reference' => 'JB-0207']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $mother = [
            'name' => 'Megan Evans',
            'phone' => '07700 900101',
            'secondaryPhone' => '029 2018 0000',
        ];

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0007',
                        'address' => 'Services, M4 Junction 32',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('mother', $mother)
            ->assertJsonPath('legs.0.mother', $mother)
            ->assertJsonPath('legs.1.mother', $mother);

        $this->assertSame(2, DeliveryJob::query()
            ->where('parent_job_id', $job->id)
            ->where('mother_name', 'Megan Evans')
            ->where('mother_phone', '07700 900101')
            ->where('mother_phone_secondary', '029 2018 0000')
            ->count());
    }

    public function test_relay_legs_inherit_the_bereavement_flag_of_a_milk_job(): void
    {
        $job = DeliveryJob::factory()->bereavement()->create(['reference' => 'JB-0208']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0008',
                        'address' => 'Services, M4 Junction 32',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('isBereavement', true)
            ->assertJsonPath('legs.0.isBereavement', true)
            ->assertJsonPath('legs.1.isBereavement', true);

        $this->assertSame(2, DeliveryJob::query()
            ->where('parent_job_id', $job->id)
            ->where('is_bereavement', true)
            ->count());
    }

    public function test_relay_legs_of_a_standard_job_stay_standard_without_litres(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0206']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0006',
                        'address' => 'Services, M4 Junction 32',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('legs.0.jobType', 'standard')
            ->assertJsonPath('legs.0.milkLitres', null)
            ->assertJsonPath('legs.0.mother', null)
            ->assertJsonPath('legs.1.jobType', 'standard')
            ->assertJsonPath('legs.1.milkLitres', null)
            ->assertJsonPath('legs.1.mother', null)
            ->assertJsonPath('legs.0.isBereavement', false)
            ->assertJsonPath('legs.1.isBereavement', false);
    }

    public function test_a_relay_stores_the_arrival_time_on_each_leg_that_delivers_to_a_rendezvous(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0210']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0010',
                        'address' => 'First handoff',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                    [
                        'placeId' => 'ChIJRendezvous0011',
                        'address' => 'Second handoff',
                        'latitude' => 51.60,
                        'longitude' => -3.20,
                        'arrivesAt' => '2026-06-20T12:45:00+01:00',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonCount(3, 'legs')
            ->assertJsonPath('plannedArrivalAt', null)
            ->assertJsonPath('legs.0.plannedArrivalAt', '2026-11-20T10:15:00+00:00')
            ->assertJsonPath('legs.1.plannedArrivalAt', '2026-06-20T11:45:00+00:00')
            ->assertJsonPath('legs.2.plannedArrivalAt', null);

        $legs = DeliveryJob::query()->where('parent_job_id', $job->id)->orderBy('leg_number')->get();
        $this->assertSame('2026-11-20 10:15:00', $legs[0]->planned_arrival_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-20 11:45:00', $legs[1]->planned_arrival_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($legs[2]->planned_arrival_at);

        foreach ($legs as $leg) {
            $this->assertNull($leg->collected_at);
            $this->assertNull($leg->delivered_at);
        }
        $this->assertNull($job->fresh()->planned_arrival_at);
    }

    public function test_relay_legs_carry_postcodes_from_the_job_and_the_rendezvous_points(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0220']);
        $job->collectionPlace->update(['postcode' => 'CF14 4XW']);
        $job->deliveryPlace->update(['postcode' => 'CF72 8XR']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0020',
                        'address' => 'First handoff',
                        'postcode' => ' CF83 1AA ',
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                    [
                        'placeId' => 'ChIJRendezvous0021',
                        'address' => 'Second handoff',
                        'latitude' => 51.60,
                        'longitude' => -3.20,
                        'arrivesAt' => '2026-11-20T11:15:00+00:00',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'legs')
            ->assertJsonPath('legs.0.collection.postcode', 'CF14 4XW')
            ->assertJsonPath('legs.0.delivery.postcode', 'CF83 1AA')
            ->assertJsonPath('legs.1.collection.postcode', 'CF83 1AA')
            ->assertJsonPath('legs.1.delivery.postcode', null)
            ->assertJsonPath('legs.2.collection.postcode', null)
            ->assertJsonPath('legs.2.delivery.postcode', 'CF72 8XR');
    }

    public function test_a_rendezvous_postcode_longer_than_10_characters_is_rejected(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0221']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [
                        'placeId' => 'ChIJRendezvous0022',
                        'address' => 'First handoff',
                        'postcode' => str_repeat('A', 11),
                        'latitude' => 51.54,
                        'longitude' => -3.12,
                        'arrivesAt' => '2026-11-20T10:15:00+00:00',
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rendezvousPoints.0.postcode']);
    }

    public function test_every_rendezvous_point_must_include_a_valid_arrival_time(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0211']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $point = [
            'placeId' => 'ChIJRendezvous0012',
            'address' => 'Handoff',
            'latitude' => 51.54,
            'longitude' => -3.12,
        ];

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    [...$point, 'arrivesAt' => '2026-11-20T10:15:00+00:00'],
                    $point,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rendezvousPoints.1.arrivesAt']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [[...$point, 'arrivesAt' => 'not-a-time']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rendezvousPoints.0.arrivesAt']);

        $this->assertFalse($job->fresh()->isRelayParent());
    }

    public function test_relay_legs_run_independent_lifecycle_and_aggregate_parent_status(): void
    {
        $bike = Bike::factory()->create();
        $riderOne = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        $riderTwo = Rider::factory()->create(['external_id' => 100002, 'name' => 'Sam Taylor']);
        $shiftOne = OperationalShift::factory()->create([
            'rider_id' => $riderOne->id,
            'bike_id' => $bike->id,
        ]);
        $shiftTwo = OperationalShift::factory()->create([
            'rider_id' => $riderTwo->id,
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
                    'arrivesAt' => '2026-11-20T10:15:00+00:00',
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
                    'arrivesAt' => '2026-11-20T10:15:00+00:00',
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
                    'arrivesAt' => '2026-11-20T10:15:00+00:00',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);
    }

    public function test_direct_lifecycle_actions_are_rejected_on_a_relay_parent(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0203']);
        DeliveryJob::factory()->create([
            'reference' => 'JB-0203-L1',
            'parent_job_id' => $job->id,
            'leg_number' => 1,
        ]);
        $shift = OperationalShift::factory()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['job']);
    }

    /**
     * @return array<string, array{0: JobStatus, 1: string}>
     */
    public static function blockedPreviousLegStatuses(): array
    {
        return [
            'previous leg new' => [JobStatus::New, 'collect'],
            'previous leg allocated, collect' => [JobStatus::Allocated, 'collect'],
            'previous leg collected, collect' => [JobStatus::Collected, 'collect'],
            'previous leg allocated, deliver' => [JobStatus::Allocated, 'deliver'],
            'previous leg collected, deliver' => [JobStatus::Collected, 'deliver'],
            'previous leg cancelled, collect' => [JobStatus::Cancelled, 'collect'],
            'previous leg cancelled, deliver' => [JobStatus::Cancelled, 'deliver'],
        ];
    }

    #[DataProvider('blockedPreviousLegStatuses')]
    public function test_a_leg_cannot_be_collected_or_delivered_until_the_previous_leg_is_delivered(JobStatus $previous, string $action): void
    {
        [, $legs] = $this->relayWithLegs([$previous, $action === 'collect' ? JobStatus::Allocated : JobStatus::Collected]);
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $payload = $action === 'collect'
            ? ['contentsConfirmed' => true, 'receiptNumber' => 'RCP-1']
            : ['recipient' => 'Dr Patel'];

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[1]->id}/actions/{$action}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action'])
            ->assertJsonPath('errors.action.0', sprintf(
                'Leg 1 must be delivered before leg 2 can be %s.',
                $action === 'collect' ? 'collected' : 'delivered',
            ));

        $this->assertSame(
            $action === 'collect' ? JobStatus::Allocated : JobStatus::Collected,
            $legs[1]->fresh()->status,
        );
    }

    public function test_a_leg_can_be_collected_and_delivered_once_the_previous_leg_is_delivered(): void
    {
        [, $legs] = $this->relayWithLegs([JobStatus::Delivered, JobStatus::Allocated]);
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[1]->id}/actions/collect", ['contentsConfirmed' => true, 'receiptNumber' => 'RCP-2'])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Collected->value)
            ->assertJsonPath('waitingForLeg', null)
            ->assertJsonPath('allowedActions', ['deliver', 'cancel']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[1]->id}/actions/deliver", ['recipient' => 'Dr Patel'])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Delivered->value);
    }

    public function test_the_first_leg_is_never_blocked(): void
    {
        [, $legs] = $this->relayWithLegs([JobStatus::Allocated, JobStatus::New, JobStatus::New]);
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[0]->id}/actions/collect", ['contentsConfirmed' => true, 'receiptNumber' => 'RCP-3'])
            ->assertOk()
            ->assertJsonPath('waitingForLeg', null)
            ->assertJsonPath('allowedActions', ['deliver', 'cancel']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[0]->id}/actions/deliver", ['recipient' => 'Handoff'])
            ->assertOk();
    }

    public function test_a_later_leg_can_still_be_allocated_while_earlier_legs_are_outstanding(): void
    {
        [, $legs] = $this->relayWithLegs([JobStatus::New, JobStatus::New, JobStatus::New]);
        $shift = OperationalShift::factory()->create();
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$legs[2]->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('waitingForLeg', 2)
            ->assertJsonPath('allowedActions', ['cancel']);
    }

    public function test_the_job_list_exposes_the_blocked_state_of_each_leg(): void
    {
        $this->relayWithLegs([JobStatus::Delivered, JobStatus::Allocated, JobStatus::Allocated, JobStatus::New]);
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/jobs/active')->assertOk();

        $response->assertJsonPath('data.0.legs.0.waitingForLeg', null)
            ->assertJsonPath('data.0.legs.0.allowedActions', [])
            ->assertJsonPath('data.0.legs.1.waitingForLeg', null)
            ->assertJsonPath('data.0.legs.1.allowedActions', ['collect', 'cancel'])
            ->assertJsonPath('data.0.legs.2.waitingForLeg', 2)
            ->assertJsonPath('data.0.legs.2.allowedActions', ['cancel'])
            ->assertJsonPath('data.0.legs.3.waitingForLeg', 3)
            ->assertJsonPath('data.0.legs.3.allowedActions', ['allocate', 'cancel'])
            ->assertJsonPath('data.0.waitingForLeg', null);
    }

    public function test_finished_legs_do_not_report_that_they_are_waiting(): void
    {
        [, $legs] = $this->relayWithLegs([JobStatus::New, JobStatus::Cancelled]);

        $this->assertNull($legs[1]->waitingForLeg());
        $this->assertSame([], $legs[1]->allowedActionNames());
    }

    public function test_the_previous_leg_is_found_by_leg_number_not_by_load_order(): void
    {
        $parent = DeliveryJob::factory()->create();
        // Created out of order, so insertion order and leg_number disagree.
        $third = DeliveryJob::factory()->allocated()->create(['parent_job_id' => $parent->id, 'leg_number' => 3]);
        $first = DeliveryJob::factory()->delivered()->create(['parent_job_id' => $parent->id, 'leg_number' => 1]);
        $second = DeliveryJob::factory()->collected()->create(['parent_job_id' => $parent->id, 'leg_number' => 2]);

        $loaded = DeliveryJob::query()->with('parentJob.legs')->findOrFail($third->id);
        $parent->load('legs');
        $parent->setRelation('legs', $parent->legs->reverse()->values());
        $loaded->parentJob->setRelation('legs', $parent->legs);

        $this->assertSame($second->id, $loaded->previousLeg()?->id);
        $this->assertSame(2, $loaded->waitingForLeg());
        $this->assertNull(DeliveryJob::query()->with('parentJob.legs')->findOrFail($second->id)->waitingForLeg());
        $this->assertNull(DeliveryJob::query()->with('parentJob.legs')->findOrFail($first->id)->previousLeg());
    }

    public function test_the_check_uses_a_fresh_read_not_the_copy_loaded_before_the_transaction(): void
    {
        [, $legs] = $this->relayWithLegs([JobStatus::Allocated, JobStatus::Allocated]);

        // A copy loaded earlier that believes leg 1 is already delivered.
        $stale = DeliveryJob::query()->with('parentJob.legs')->findOrFail($legs[1]->id);
        $stale->parentJob->legs->first()->status = JobStatus::Delivered;

        $actor = User::factory()->create();

        try {
            app(DeliveryJobService::class)->collect($actor, $stale, ['contentsConfirmed' => true, 'receiptNumber' => 'RCP-4']);
            $this->fail('Collect should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Leg 1 must be delivered before leg 2 can be collected.'],
                $exception->errors()['action'],
            );
        }

        $this->assertSame(JobStatus::Allocated, $legs[1]->fresh()->status);
    }

    public function test_listing_jobs_does_not_query_per_leg(): void
    {
        $this->relayWithLegs([JobStatus::Delivered, JobStatus::Allocated]);
        $service = app(DeliveryJobService::class);

        $countQueries = function () use ($service): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $jobs = $service->listByScope(JobScope::Active);
            // Serialise as the endpoint does, which is what asks each leg for its allowed actions.
            $request = Request::create('/api/jobs/active');
            DeliveryJobResource::collection($jobs)->toResponse($request);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $countQueries();

        $this->relayWithLegs([JobStatus::Delivered, JobStatus::Delivered, JobStatus::Collected, JobStatus::New, JobStatus::New, JobStatus::New]);

        $this->assertSame($small, $countQueries());
    }

    public function test_the_relay_and_cancel_responses_report_blocked_legs_without_extra_queries(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0230']);
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/relay", [
                'rendezvousPoints' => [
                    ['placeId' => 'ChIJRendezvous0030', 'address' => 'First', 'latitude' => 51.5, 'longitude' => -3.1, 'arrivesAt' => '2026-11-20T10:15:00+00:00'],
                    ['placeId' => 'ChIJRendezvous0031', 'address' => 'Second', 'latitude' => 51.6, 'longitude' => -3.2, 'arrivesAt' => '2026-11-20T11:15:00+00:00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('legs.0.waitingForLeg', null)
            ->assertJsonPath('legs.1.waitingForLeg', 1)
            ->assertJsonPath('legs.2.waitingForLeg', 2);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('legs.1.waitingForLeg', null)
            ->assertJsonPath('legs.1.allowedActions', []);
    }

    public function test_delivery_jobs_are_indexed_by_parent_and_leg_number(): void
    {
        $this->assertTrue(Schema::hasIndex('delivery_jobs', ['parent_job_id', 'leg_number'], 'unique'));
    }

    /**
     * Builds a relay parent whose legs (numbered from 1) take the given statuses.
     *
     * @param  list<JobStatus>  $statuses
     * @return array{0: DeliveryJob, 1: list<DeliveryJob>}
     */
    private function relayWithLegs(array $statuses): array
    {
        $parent = DeliveryJob::factory()->create();

        $legs = [];
        foreach ($statuses as $index => $status) {
            $legs[] = DeliveryJob::factory()->create([
                'status' => $status,
                'parent_job_id' => $parent->id,
                'leg_number' => $index + 1,
            ]);
        }

        $parent->update(['status' => JobStatus::Allocated]);

        return [$parent, $legs];
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
