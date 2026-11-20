<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Enums\JobStatus;
use App\Models\DeliveryJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_rider_sees_estimated_times_on_listed_jobs(): void
    {
        DeliveryJob::factory()->create([
            'reference' => 'JB-1042',
            'estimated_collection_at' => '2026-11-20 09:30:00',
            'estimated_delivery_at' => null,
        ]);

        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.estimatedCollectionAt', '2026-11-20T09:30:00+00:00')
            ->assertJsonPath('data.0.estimatedDeliveryAt', null);
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

    #[DataProvider('everyRoleThatCanViewJobs')]
    public function test_active_scope_never_hides_a_job_by_its_date(string $role): void
    {
        // The 24 hour hiding belongs to the completed list only. Whatever its
        // scheduled day (past, today, next week, next year) or none at all, an
        // active job is listed.
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->create(['reference' => 'JB-1040', 'scheduled_collection_at' => null]);
        DeliveryJob::factory()->create(['reference' => 'JB-1041', 'scheduled_collection_at' => '2026-08-05 09:00:00']);
        DeliveryJob::factory()->create(['reference' => 'JB-1042', 'scheduled_collection_at' => '2026-08-29 15:00:00']);
        DeliveryJob::factory()->create(['reference' => 'JB-1043', 'scheduled_collection_at' => '2026-09-05 10:00:00']);
        DeliveryJob::factory()->allocated()->create(['reference' => 'JB-1044', 'scheduled_collection_at' => '2026-09-05 11:00:00']);
        DeliveryJob::factory()->create(['reference' => 'JB-1045', 'scheduled_collection_at' => '2027-08-29 10:00:00']);

        // Created and last touched months ago, so the 24 hour window (which looks at updated_at)
        // would hide every one of them if it were ever applied to the active list. The
        // "show all previous jobs" setting does not matter here: it only affects Completed.
        DeliveryJob::query()->update([
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->fakeDirectory($this->volunteer($role));
        $this->mockVerifiedClaims();

        $references = collect(
            $this->withToken('valid-token')
                ->getJson('/api/jobs/active')
                ->assertOk()
                ->assertJsonCount(6, 'data')
                ->json('data')
        )->pluck('reference')->all();

        $this->assertEqualsCanonicalizing(
            ['JB-1040', 'JB-1041', 'JB-1042', 'JB-1043', 'JB-1044', 'JB-1045'],
            $references,
        );
    }

    public function test_active_scope_returns_a_job_scheduled_next_week_with_its_time(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->create([
            'reference' => 'JB-1050',
            'scheduled_collection_at' => '2026-09-05 10:00:00',
        ]);
        DeliveryJob::factory()->create(['reference' => 'JB-1051', 'scheduled_collection_at' => null]);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['reference' => 'JB-1050', 'scheduledCollectionAt' => '2026-09-05T10:00:00+00:00'])
            ->assertJsonFragment(['reference' => 'JB-1051', 'scheduledCollectionAt' => null]);
    }

    public function test_active_scope_stays_in_last_updated_order_whatever_the_scheduled_time(): void
    {
        // Which jobs are "due today" depends on the viewer's own day, which the
        // API does not know, so it does not reorder by schedule. The app does.
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->create(['reference' => 'JB-1060', 'scheduled_collection_at' => '2026-09-05 10:00:00']);

        Carbon::setTestNow('2026-08-29 10:00:01');
        DeliveryJob::factory()->create(['reference' => 'JB-1061', 'scheduled_collection_at' => null]);

        Carbon::setTestNow('2026-08-29 10:00:02');
        DeliveryJob::factory()->create(['reference' => 'JB-1062', 'scheduled_collection_at' => '2026-08-30 08:00:00']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1062')
            ->assertJsonPath('data.1.reference', 'JB-1061')
            ->assertJsonPath('data.2.reference', 'JB-1060');
    }

    public function test_active_jobs_last_updated_at_the_same_moment_are_listed_highest_id_first(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        $one = DeliveryJob::factory()->create(['reference' => 'JB-1080']);
        $two = DeliveryJob::factory()->create(['reference' => 'JB-1081', 'scheduled_collection_at' => '2026-09-05 10:00:00']);
        $three = DeliveryJob::factory()->create(['reference' => 'JB-1082']);

        $expected = collect([$one, $two, $three])
            ->sortByDesc('id')
            ->pluck('reference')
            ->values()
            ->all();

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->assertSame(
            $expected,
            collect(
                $this->withToken('valid-token')->getJson('/api/jobs/active')->assertOk()->json('data')
            )->pluck('reference')->all(),
        );
    }

    public function test_a_future_relay_parent_is_listed_once_without_its_legs_as_separate_jobs(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        $parent = DeliveryJob::factory()->create([
            'reference' => 'JB-1070',
            'scheduled_collection_at' => '2026-09-05 10:00:00',
        ]);
        DeliveryJob::factory()->create([
            'reference' => 'JB-1070-L1',
            'parent_job_id' => $parent->id,
            'leg_number' => 1,
            'scheduled_collection_at' => '2026-09-05 10:00:00',
        ]);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'JB-1070');
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

    public function test_a_controller_sees_the_mothers_contact_on_milk_jobs_and_null_on_standard_jobs(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-1100']);
        Carbon::setTestNow('2026-08-29 11:00:00');
        DeliveryJob::factory()->create(['reference' => 'JB-1101']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1101')
            ->assertJsonPath('data.0.mother', null)
            ->assertJsonPath('data.1.reference', 'JB-1100')
            ->assertJsonPath('data.1.mother', [
                'name' => 'Megan Evans',
                'phone' => '07700 900101',
                'secondaryPhone' => '029 2018 0000',
            ]);
    }

    public function test_an_admin_sees_the_mothers_contact(): void
    {
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-1100']);

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
            ->assertJsonPath('data.0.mother.name', 'Megan Evans')
            ->assertJsonPath('data.0.mother.phone', '07700 900101');
    }

    #[DataProvider('rolesThatCannotSeeTheMother')]
    public function test_roles_that_cannot_create_jobs_never_receive_the_mothers_contact(string $role): void
    {
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-1100']);

        $this->fakeDirectory($this->volunteer($role));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1100')
            ->assertJsonPath('data.0.jobType', 'milk')
            ->assertJsonMissingPath('data.0.mother');

        $body = $response->getContent();
        $this->assertStringNotContainsString('Megan Evans', $body);
        $this->assertStringNotContainsString('07700 900101', $body);
        $this->assertStringNotContainsString('029 2018 0000', $body);
    }

    #[DataProvider('everyRoleThatCanViewJobs')]
    public function test_every_role_that_can_view_jobs_is_told_when_a_job_is_a_bereavement(string $role): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        DeliveryJob::factory()->bereavement()->create(['reference' => 'JB-1100']);
        Carbon::setTestNow('2026-08-29 11:00:00');
        DeliveryJob::factory()->milk()->create(['reference' => 'JB-1101']);
        Carbon::setTestNow('2026-08-29 12:00:00');
        DeliveryJob::factory()->create(['reference' => 'JB-1102']);

        $this->fakeDirectory($this->volunteer($role));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/jobs/active')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'JB-1102')
            ->assertJsonPath('data.0.isBereavement', false)
            ->assertJsonPath('data.1.reference', 'JB-1101')
            ->assertJsonPath('data.1.isBereavement', false)
            ->assertJsonPath('data.2.reference', 'JB-1100')
            ->assertJsonPath('data.2.isBereavement', true);
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
