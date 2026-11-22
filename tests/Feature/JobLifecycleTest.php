<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\Bike;
use App\Models\DeliveryJob;
use App\Models\OperationalShift;
use App\Models\Rider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobLifecycleTest extends TestCase
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

    public function test_unauthenticated_lifecycle_requests_are_denied(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->postJson("/api/jobs/{$job->id}/actions/allocate", [])->assertUnauthorized();
        $this->postJson("/api/jobs/{$job->id}/actions/collect", [])->assertUnauthorized();
        $this->postJson("/api/jobs/{$job->id}/actions/deliver", [])->assertUnauthorized();
        $this->postJson("/api/jobs/{$job->id}/cancel", [])->assertUnauthorized();
    }

    public function test_a_rider_cannot_run_lifecycle_actions(): void
    {
        $job = DeliveryJob::factory()->create();
        $shift = OperationalShift::factory()->create();

        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertForbidden();
    }

    public function test_a_controller_can_allocate_collect_deliver_a_job(): void
    {
        $bike = Bike::factory()->create();
        $rider = Rider::factory()->create(['external_id' => 100001, 'name' => 'Alex Morgan']);
        $shift = OperationalShift::factory()->create([
            'rider_id' => $rider->id,
            'bike_id' => $bike->id,
        ]);
        $job = DeliveryJob::factory()->create(['reference' => 'JB-0100']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Allocated->value)
            ->assertJsonPath('allocatedRider.id', '100001')
            ->assertJsonPath('allocatedRider.name', 'Alex Morgan')
            ->assertJsonPath('allocatedRider.shiftId', $shift->id)
            ->assertJsonPath('allowedActions', ['allocate', 'collect', 'cancel']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/collect", [
                'contentsConfirmed' => true,
                'receiptNumber' => 'RCP-9001',
                'collectedAt' => '2026-08-29T12:30:00+01:00',
            ])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Collected->value)
            ->assertJsonPath('collectionRecord.receiptNumber', 'RCP-9001')
            ->assertJsonMissingPath('collectionRecord.suitablySealed')
            ->assertJsonMissingPath('collectionRecord.sealNumber')
            ->assertJsonPath('allowedActions', ['deliver', 'cancel']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [
                'recipient' => 'Dr Patel',
                'deliveredAt' => '2026-08-29T14:00:00+01:00',
            ])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Delivered->value)
            ->assertJsonPath('deliveryRecord.recipient', 'Dr Patel')
            ->assertJsonPath('allowedActions', []);

        $this->assertDatabaseHas('delivery_legs', [
            'id' => $job->id,
            'status' => JobStatus::Delivered->value,
            'operational_shift_id' => $shift->id,
            'receipt_number' => 'RCP-9001',
            'recipient' => 'Dr Patel',
        ]);
    }

    public function test_allocate_rejects_a_logged_off_shift(): void
    {
        $shift = OperationalShift::factory()->loggedOff()->create();
        $job = DeliveryJob::factory()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", ['shiftId' => $shift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shiftId']);
    }

    public function test_collect_ignores_legacy_seal_keys_from_an_older_client(): void
    {
        $job = DeliveryJob::factory()->allocated()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/collect", [
                'contentsConfirmed' => true,
                'suitablySealed' => false,
                'sealNumber' => 'SEAL-42',
                'receiptNumber' => 'RCP-1',
            ])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Collected->value)
            ->assertJsonPath('collectionRecord.receiptNumber', 'RCP-1')
            ->assertJsonMissingPath('collectionRecord.suitablySealed')
            ->assertJsonMissingPath('collectionRecord.sealNumber');
    }

    public function test_delivery_jobs_table_has_no_seal_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('delivery_legs', 'suitably_sealed'));
        $this->assertFalse(Schema::hasColumn('delivery_legs', 'seal_number'));
        $this->assertTrue(Schema::hasColumn('delivery_legs', 'receipt_number'));
    }

    public function test_invalid_transitions_are_rejected_with_allowed_actions(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/allocate", [
                'shiftId' => OperationalShift::factory()->create()->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action'])
            ->assertJson([
                'errors' => [
                    'action' => [
                        'Job is Collected. Allowed actions: deliver, cancel.',
                    ],
                ],
            ]);
    }

    public function test_a_controller_can_cancel_an_in_progress_job(): void
    {
        $job = DeliveryJob::factory()->allocated()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/cancel", ['reason' => 'Sender withdrew request'])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Cancelled->value)
            ->assertJsonPath('cancellation.reason', 'Sender withdrew request')
            ->assertJsonPath('allowedActions', []);
    }

    public function test_unknown_action_paths_return_not_found(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/relay", [])
            ->assertNotFound();
    }

    public function test_deliver_with_no_recipient_succeeds_and_stores_null(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", ['noRecipient' => true])
            ->assertOk()
            ->assertJsonPath('status', JobStatus::Delivered->value)
            ->assertJsonPath('deliveryRecord.recipient', null)
            ->assertJsonPath('deliveryRecord.noRecipient', true);

        $this->assertDatabaseHas('delivery_legs', [
            'id' => $job->id,
            'status' => JobStatus::Delivered->value,
            'recipient' => null,
            'no_recipient' => true,
        ]);
    }

    public function test_deliver_with_no_recipient_ignores_a_recipient_sent_as_well(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [
                'noRecipient' => true,
                'recipient' => 'Dr Patel',
            ])
            ->assertOk()
            ->assertJsonPath('deliveryRecord.recipient', null)
            ->assertJsonPath('deliveryRecord.noRecipient', true);

        $this->assertDatabaseHas('delivery_legs', [
            'id' => $job->id,
            'recipient' => null,
            'no_recipient' => true,
        ]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noRecipientTruthyValues(): array
    {
        return ['true' => [true], 'int 1' => [1], 'string 1' => ['1'], 'string true' => ['true']];
    }

    #[DataProvider('noRecipientTruthyValues')]
    public function test_deliver_accepts_truthy_no_recipient_values(mixed $value): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", ['noRecipient' => $value])
            ->assertOk()
            ->assertJsonPath('deliveryRecord.noRecipient', true)
            ->assertJsonPath('deliveryRecord.recipient', null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noRecipientFalsyValues(): array
    {
        return ['false' => [false], 'int 0' => [0], 'string 0' => ['0'], 'string false' => ['false'], 'null' => [null], 'empty string' => ['']];
    }

    #[DataProvider('noRecipientFalsyValues')]
    public function test_deliver_treats_falsy_no_recipient_values_as_a_normal_delivery(mixed $value): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [
                'noRecipient' => $value,
                'recipient' => 'Dr Patel',
            ])
            ->assertOk()
            ->assertJsonPath('deliveryRecord.noRecipient', false)
            ->assertJsonPath('deliveryRecord.recipient', 'Dr Patel');

        $this->assertDatabaseHas('delivery_legs', [
            'id' => $job->id,
            'recipient' => 'Dr Patel',
            'no_recipient' => false,
        ]);
    }

    public function test_deliver_without_a_recipient_or_the_flag_is_rejected_on_recipient(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient']);

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", ['recipient' => '   ', 'noRecipient' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient']);

        $this->assertDatabaseHas('delivery_legs', [
            'id' => $job->id,
            'status' => JobStatus::Collected->value,
        ]);
    }

    public function test_deliver_rejects_an_unrecognised_no_recipient_value(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", ['noRecipient' => 'maybe', 'recipient' => 'Dr Patel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['noRecipient']);
    }

    public function test_a_legacy_client_without_the_flag_still_delivers_with_a_recipient(): void
    {
        $job = DeliveryJob::factory()->collected()->create();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson("/api/jobs/{$job->id}/actions/deliver", ['recipient' => 'Dr Patel'])
            ->assertOk()
            ->assertJsonPath('deliveryRecord.recipient', 'Dr Patel')
            ->assertJsonPath('deliveryRecord.noRecipient', false);
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
