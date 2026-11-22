<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\DeliveryRequest;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

class ShowDeliveryJobTest extends TestCase
{
    use RefreshDatabase, SignsInVolunteers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useThreeRingsConfig();
        Carbon::setTestNow('2026-11-20 12:00:00');
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->getJson("/api/jobs/{$job->id}")->assertUnauthorized();
    }

    public function test_a_job_opens_by_id_with_the_same_shape_as_the_lists(): void
    {
        $job = DeliveryJob::factory()->create(['reference' => 'JB-1042']);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $fromList = $this->withToken('valid-token')->getJson('/api/jobs/active')->json('data.0');

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('id', $job->id)
            ->assertJsonPath('reference', 'JB-1042')
            ->assertExactJson($fromList);
    }

    public function test_an_old_completed_job_still_opens_by_id_even_with_the_setting_off(): void
    {
        $old = DeliveryJob::factory()->delivered()->create([
            'reference' => 'JB-1001',
            'delivered_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/jobs/completed')->assertJsonCount(0, 'data');

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$old->id}")
            ->assertOk()
            ->assertJsonPath('reference', 'JB-1001')
            ->assertJsonPath('status', JobStatus::Delivered->value);
    }

    public function test_it_does_not_depend_on_the_users_completed_jobs_setting(): void
    {
        $old = DeliveryJob::factory()->cancelled()->create([
            'cancelled_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);
        $user = User::factory()->create(['email' => 'rider@bloodbikes.wales']);
        UserSetting::query()->create(['user_id' => $user->id, 'show_completed_jobs' => true]);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson("/api/jobs/{$old->id}")->assertOk();
    }

    public function test_the_job_id_does_not_collide_with_the_list_scopes(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/jobs/active')->assertOk()->assertJsonStructure(['data']);
        $this->withToken('valid-token')->getJson('/api/jobs/completed')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_a_malformed_id_is_not_found(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/jobs/not-a-uuid')->assertNotFound();
        $this->withToken('valid-token')->getJson('/api/jobs/all')->assertNotFound();
    }

    #[DataProvider('everyRoleThatCanViewJobs')]
    public function test_every_role_that_can_view_jobs_can_open_a_job(string $role): void
    {
        $job = DeliveryJob::factory()->create();

        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson("/api/jobs/{$job->id}")->assertOk();
    }

    public function test_a_signed_in_user_without_the_view_jobs_capability_is_forbidden(): void
    {
        $job = DeliveryJob::factory()->create();

        $this->fakeDirectory([]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson("/api/jobs/{$job->id}")->assertForbidden();
    }

    public function test_a_controller_sees_the_mothers_contact(): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('mother.name', 'Megan Evans')
            ->assertJsonPath('mother.phone', '07700 900101');
    }

    #[DataProvider('rolesThatCannotSeeTheMother')]
    public function test_roles_that_cannot_create_jobs_never_receive_the_mothers_contact(string $role): void
    {
        $job = DeliveryJob::factory()->milk()->create();

        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')
            ->getJson("/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('jobType', 'milk')
            ->assertJsonMissingPath('mother');

        $body = $response->getContent();
        $this->assertStringNotContainsString('Megan Evans', $body);
        $this->assertStringNotContainsString('07700 900101', $body);
        $this->assertStringNotContainsString('029 2018 0000', $body);
    }

    public function test_a_relay_parent_comes_with_its_legs_and_a_leg_knows_what_it_waits_for(): void
    {
        $parent = DeliveryJob::factory()->create(['reference' => 'JB-2001']);
        $legOne = DeliveryJob::factory()->create([
            'reference' => 'JB-2001-L1',
            'parent_job_id' => $parent->id,
            'leg_number' => 1,
        ]);
        $legTwo = DeliveryJob::factory()->create([
            'reference' => 'JB-2001-L2',
            'parent_job_id' => $parent->id,
            'leg_number' => 2,
        ]);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('isRelay', true)
            ->assertJsonCount(2, 'legs')
            ->assertJsonPath('legs.0.reference', 'JB-2001-L1')
            ->assertJsonPath('legs.1.waitingForLeg', 1);

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$legTwo->id}")
            ->assertOk()
            ->assertJsonPath('reference', 'JB-2001-L2')
            ->assertJsonPath('parentJobId', $parent->id)
            ->assertJsonPath('waitingForLeg', 1)
            ->assertJsonPath('allowedActions', ['allocate', 'cancel']);

        $this->withToken('valid-token')
            ->getJson("/api/jobs/{$legOne->id}")
            ->assertOk()
            ->assertJsonPath('waitingForLeg', null);
    }

    public function test_opening_a_relay_does_not_query_per_leg(): void
    {
        $small = $this->relayWithLegs(2);
        $large = $this->relayWithLegs(6);

        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        // Warm the directory/role caches so only the job queries are counted.
        $this->withToken('valid-token')->getJson('/api/me')->assertOk();

        $this->assertSame(
            $this->queriesFor("/api/jobs/{$small->id}"),
            $this->queriesFor("/api/jobs/{$large->id}"),
        );
        $this->assertSame(
            $this->queriesFor("/api/jobs/{$small->legs()->first()->id}"),
            $this->queriesFor("/api/jobs/{$large->legs()->orderByDesc('leg_number')->first()->id}"),
        );
    }

    private function relayWithLegs(int $count): DeliveryRequest
    {
        $seed = DeliveryJob::factory()->create();
        $requestId = $seed->request_id;
        foreach (range(1, $count) as $number) {
            DeliveryJob::factory()->create([
                'request_id' => $requestId,
                'leg_number' => $number,
            ]);
        }

        return DeliveryRequest::query()->with('legs')->findOrFail($requestId);
    }

    private function queriesFor(string $path): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken('valid-token')->getJson($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesThatCannotSeeTheMother(): array
    {
        return [
            'rider' => ['Rider'],
            'driver' => ['Driver'],
            'trustee' => ['Trustee'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyRoleThatCanViewJobs(): array
    {
        return [
            'controller' => ['Controller'],
            'rider' => ['Rider'],
            'driver' => ['Driver'],
            'trustee' => ['Trustee'],
        ];
    }
}
