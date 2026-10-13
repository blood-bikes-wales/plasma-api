<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\OutboundPostcodeArea;
use App\Models\OutboundPostcodeAreaAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsServiceAreas;
use Tests\TestCase;

class PostcodeAreaTest extends TestCase
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
        $this->getJson('/api/service-areas')->assertUnauthorized();
        $this->getJson('/api/postcode-areas')->assertUnauthorized();
        $this->postJson('/api/postcode-areas', [])->assertUnauthorized();
    }

    public function test_a_rider_cannot_view_service_areas(): void
    {
        $this->fakeDirectory($this->volunteer('Rider'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/service-areas')
            ->assertForbidden();
    }

    public function test_a_controller_lists_distinct_active_service_areas(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/service-areas')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'E')
            ->assertJsonPath('data.0.name', 'East')
            ->assertJsonPath('data.1.code', 'NW')
            ->assertJsonMissing(['code' => 'X']);
    }

    public function test_a_controller_lists_active_mappings_only(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/postcode-areas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.outboundPostcode', 'CF14');
    }

    public function test_a_trustee_can_include_inactive_mappings(): void
    {
        $this->fakeDirectory($this->volunteer('Trustees'));
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->getJson('/api/postcode-areas?includeInactive=1')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_lookup_returns_cached_active_mapping(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/postcode-areas/lookup?postcode=CF14%204XW')
            ->assertOk()
            ->assertJsonPath('data.outboundPostcode', 'CF14')
            ->assertJsonPath('data.areaCode', 'E');

        $this->assertTrue(Cache::has('postcode-area:CF14'));
    }

    public function test_a_trustee_creates_and_updates_a_mapping(): void
    {
        $this->fakeDirectory($this->volunteer('Trustees'));
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $create = $this->withToken('valid-token')
            ->postJson('/api/postcode-areas', [
                'outboundPostcode' => 'sa1',
                'areaCode' => 's',
                'areaFullName' => 'Swansea',
            ])
            ->assertCreated()
            ->assertJsonPath('outboundPostcode', 'SA1')
            ->assertJsonPath('areaCode', 'S');

        $id = $create->json('id');
        $this->assertDatabaseHas('outbound_postcode_area_audits', [
            'outbound_postcode_area_id' => $id,
            'action' => 'created',
        ]);

        $this->withToken('valid-token')
            ->patchJson("/api/postcode-areas/{$id}", [
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertSame(1, OutboundPostcodeAreaAudit::query()->where('action', 'deactivated')->count());
        $this->assertFalse(Cache::has('postcode-area:SA1'));
    }

    public function test_a_controller_cannot_manage_mappings(): void
    {
        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/postcode-areas', [
                'outboundPostcode' => 'SA1',
                'areaCode' => 'S',
                'areaFullName' => 'Swansea',
            ])
            ->assertForbidden();
    }

    public function test_import_upserts_and_preserves_sort_order(): void
    {
        $existing = OutboundPostcodeArea::query()->where('outbound_postcode', 'CF14')->firstOrFail();
        $originalSort = $existing->sort_order;

        $this->fakeDirectory($this->volunteer('Trustees'));
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $csv = implode("\n", [
            'outboundPostcode,areaCode,areaFullName,active',
            'CF14,E,East Cardiff,true',
            'SA1,S,Swansea,true',
        ]);

        $file = UploadedFile::fake()->createWithContent('mappings.csv', $csv);

        $this->withToken('valid-token')
            ->post('/api/postcode-areas/import', ['file' => $file], [
                'Authorization' => 'Bearer valid-token',
            ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1);

        $existing->refresh();
        $this->assertSame($originalSort, $existing->sort_order);
        $this->assertSame('East Cardiff', $existing->area_full_name);
        $this->assertDatabaseHas('outbound_postcode_areas', [
            'outbound_postcode' => 'SA1',
            'area_code' => 'S',
        ]);
    }

    public function test_rejects_invalid_outward_postcode(): void
    {
        $this->fakeDirectory($this->volunteer('Trustees'));
        $this->mockVerifiedClaims(['email' => 'trustee@bloodbikes.wales']);

        $this->withToken('valid-token')
            ->postJson('/api/postcode-areas', [
                'outboundPostcode' => 'INVALID',
                'areaCode' => 'E',
                'areaFullName' => 'East',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['outboundPostcode']);
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
                ['volunteer_property' => ['name' => 'Email', 'value' => match ($roleName) {
                    'Trustees' => 'trustee@bloodbikes.wales',
                    default => 'rider@bloodbikes.wales',
                }]],
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
        $claims = array_merge([
            'sub' => 'google-user-1',
            'email' => 'rider@bloodbikes.wales',
            'email_verified' => true,
            'hd' => 'bloodbikes.wales',
            'name' => 'Alex Morgan',
        ], $overrides);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims): void {
            $mock->shouldReceive('verify')
                ->andReturn($claims);
        });
    }
}
