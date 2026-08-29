<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Bike;
use App\Models\MileageReading;
use App\Models\OperationalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectoryTest extends TestCase
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

    public function test_riders_can_search_the_volunteer_directory_by_name_role_and_area(): void
    {
        $this->fakeDirectory([
            [
                'id' => 100001,
                'name' => 'Alex Morgan',
                'roles' => [
                    ['role' => ['id' => 5001, 'name' => 'Rider']],
                    ['role' => ['id' => 5002, 'name' => 'Controller']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                    ['volunteer_property' => ['name' => 'Telephone', 'value' => '07700 900001']],
                    ['volunteer_property' => ['name' => 'Area', 'value' => 'South Wales']],
                ],
            ],
            [
                'id' => 100002,
                'name' => 'Bethan Hughes',
                'roles' => [
                    ['role' => ['id' => 5003, 'name' => 'Driver']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Area', 'value' => 'North Wales']],
                ],
            ],
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/directory/volunteers')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->withToken('valid-token')
            ->getJson('/api/directory/volunteers?q=alex&role=rider&area=south')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '100001')
            ->assertJsonPath('data.0.name', 'Alex Morgan')
            ->assertJsonPath('data.0.roles', ['Rider', 'Controller'])
            ->assertJsonPath('data.0.area', 'South Wales')
            ->assertJsonPath('data.0.email', 'rider@bloodbikes.wales')
            ->assertJsonPath('data.0.phone', '07700 900001');
    }

    public function test_riders_can_search_bikes_by_registration(): void
    {
        Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'last_recorded_mileage' => 15234,
        ]);
        Bike::factory()->create([
            'registration' => 'CF34 DEF',
            'last_recorded_mileage' => 9820,
        ]);

        $this->fakeDirectory([$this->riderVolunteer()]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/directory/bikes')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->withToken('valid-token')
            ->getJson('/api/directory/bikes?q=cf12')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registration', 'CF12 ABC')
            ->assertJsonPath('data.0.lastRecordedMileage', 15234);
    }

    public function test_bike_detail_includes_append_only_mileage_history(): void
    {
        $bike = Bike::factory()->create([
            'registration' => 'CF12 ABC',
            'last_recorded_mileage' => 15300,
        ]);
        $shift = OperationalShift::factory()->for($bike)->create([
            'end_mileage' => 15300,
        ]);
        MileageReading::factory()->for($bike)->for($shift)->create([
            'mileage' => 15300,
            'reason' => 'Routine logoff',
            'recorded_at' => now()->subHour(),
        ]);

        $this->fakeDirectory([$this->riderVolunteer()]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson("/api/directory/bikes/{$bike->id}")
            ->assertOk()
            ->assertJsonPath('data.registration', 'CF12 ABC')
            ->assertJsonPath('data.lastRecordedMileage', 15300)
            ->assertJsonCount(1, 'data.mileageHistory')
            ->assertJsonPath('data.mileageHistory.0.mileage', 15300)
            ->assertJsonPath('data.mileageHistory.0.reason', 'Routine logoff')
            ->assertJsonPath('data.mileageHistory.0.shiftId', $shift->id);
    }

    public function test_users_without_plasma_roles_cannot_use_the_directory(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/directory/volunteers?q=alex')
            ->assertForbidden();
    }

    public function test_volunteer_directory_returns_503_when_three_rings_is_unavailable(): void
    {
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => 'google-sub-123',
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/directory/volunteers?q=alex')
            ->assertStatus(503);
    }

    /**
     * @return array<string, mixed>
     */
    private function riderVolunteer(): array
    {
        return [
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => 'Rider']],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|array<string, mixed>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        $list = array_is_list($volunteers) ? $volunteers : [$volunteers];

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
