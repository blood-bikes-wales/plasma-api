<?php

namespace Tests\Feature;

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\JobContentType;
use App\Models\User;
use Database\Seeders\JobContentTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobContentTypeTest extends TestCase
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

        // The migration seeds a starter list; these tests control exactly what exists.
        JobContentType::query()->delete();
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(JobContentTypeSeeder::class);
        $this->seed(JobContentTypeSeeder::class);

        $names = JobContentType::query()->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(JobContentTypeSeeder::CONTENTS, $names);
    }

    public function test_the_seeder_leaves_trustee_edits_alone(): void
    {
        JobContentType::factory()->inactive()->create(['name' => 'milk']);

        $this->seed(JobContentTypeSeeder::class);

        $this->assertSame(1, JobContentType::query()->whereRaw('LOWER(name) = ?', ['milk'])->count());
        $this->assertFalse(JobContentType::query()->whereRaw('LOWER(name) = ?', ['milk'])->firstOrFail()->active);
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $entry = JobContentType::factory()->create();

        $this->getJson('/api/job-contents')->assertUnauthorized();
        $this->postJson('/api/job-contents', [])->assertUnauthorized();
        $this->patchJson("/api/job-contents/{$entry->id}", [])->assertUnauthorized();
    }

    #[DataProvider('rolesThatCannotUseTheList')]
    public function test_riders_and_drivers_are_forbidden_on_every_endpoint(string $role): void
    {
        $entry = JobContentType::factory()->create();

        $this->fakeDirectory($this->volunteer($role));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/job-contents')->assertForbidden();
        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => 'Blood samples'])
            ->assertForbidden();
        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['active' => false])
            ->assertForbidden();

        $this->assertTrue($entry->fresh()->active);
        $this->assertSame(1, JobContentType::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesThatCannotUseTheList(): array
    {
        return [
            'rider' => ['Rider'],
            'driver' => ['Driver'],
        ];
    }

    public function test_a_user_with_no_roles_is_forbidden(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')->getJson('/api/job-contents')->assertForbidden();
    }

    public function test_a_controller_sees_active_entries_only_without_management_fields(): void
    {
        JobContentType::factory()->create(['name' => 'Plasma', 'sort_order' => 2]);
        JobContentType::factory()->create(['name' => 'Blood samples', 'sort_order' => 1]);
        JobContentType::factory()->inactive()->create(['name' => 'Retired thing', 'sort_order' => 3]);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $response = $this->withToken('valid-token')->getJson('/api/job-contents');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Blood samples')
            ->assertJsonPath('data.1.name', 'Plasma')
            ->assertJsonMissingPath('data.0.active')
            ->assertJsonMissingPath('data.0.sortOrder');
    }

    public function test_a_controller_cannot_create_rename_or_deactivate(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'Plasma']);

        $this->fakeDirectory($this->volunteer('Controller'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => 'Blood samples'])
            ->assertForbidden();
        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['name' => 'Other', 'active' => false])
            ->assertForbidden();

        $this->assertSame('Plasma', $entry->fresh()->name);
        $this->assertTrue($entry->fresh()->active);
        $this->assertSame(1, JobContentType::query()->count());
    }

    public function test_a_trustee_sees_every_entry_with_management_fields(): void
    {
        JobContentType::factory()->create(['name' => 'Plasma', 'sort_order' => 1]);
        JobContentType::factory()->inactive()->create(['name' => 'Retired thing', 'sort_order' => 2]);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/job-contents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Plasma')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.sortOrder', 1)
            ->assertJsonPath('data.1.name', 'Retired thing')
            ->assertJsonPath('data.1.active', false);
    }

    public function test_a_trustee_creates_a_trimmed_entry_at_the_end_of_the_list(): void
    {
        JobContentType::factory()->create(['name' => 'Plasma', 'sort_order' => 5]);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => '  Blood   samples '])
            ->assertCreated()
            ->assertJsonPath('name', 'Blood samples')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sortOrder', 6)
            ->assertJsonMissingPath('data');

        $this->assertDatabaseHas('job_content_types', ['name' => 'Blood samples', 'sort_order' => 6]);
    }

    public function test_a_trustee_can_set_the_sort_order_on_create(): void
    {
        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => 'Milk', 'sortOrder' => 0])
            ->assertCreated()
            ->assertJsonPath('sortOrder', 0);
    }

    public function test_an_admin_can_manage_the_list(): void
    {
        User::factory()->admin()->create([
            'email' => 'rider@bloodbikes.wales',
            'google_id' => null,
        ]);

        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => []]),
        ]);
        $this->mockVerifiedClaims();

        $id = $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => 'Blood samples'])
            ->assertCreated()
            ->json('id');

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->withToken('valid-token')
            ->getJson('/api/job-contents')
            ->assertOk()
            ->assertJsonPath('data.0.active', false);
    }

    public function test_create_validates_the_name(): void
    {
        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => str_repeat('a', 101), 'sortOrder' => -1, 'active' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sortOrder', 'active']);
    }

    public function test_names_are_unique_ignoring_case_and_whitespace(): void
    {
        JobContentType::factory()->create(['name' => 'Blood samples']);
        JobContentType::factory()->inactive()->create(['name' => 'Milk']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => 'blood SAMPLES'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // Retired entries still reserve their name; reactivate instead of duplicating.
        $this->withToken('valid-token')
            ->postJson('/api/job-contents', ['name' => ' milk '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(2, JobContentType::query()->count());
    }

    public function test_the_database_enforces_case_insensitive_uniqueness(): void
    {
        JobContentType::factory()->create(['name' => 'Blood samples']);

        $this->expectException(UniqueConstraintViolationException::class);

        JobContentType::factory()->create(['name' => 'BLOOD SAMPLES']);
    }

    public function test_a_trustee_renames_an_entry(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'Blod samples']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['name' => ' Blood samples '])
            ->assertOk()
            ->assertJsonPath('id', $entry->id)
            ->assertJsonPath('name', 'Blood samples');

        $this->assertSame('Blood samples', $entry->fresh()->name);
    }

    public function test_an_entry_can_change_only_the_case_of_its_own_name(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'blood samples']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['name' => 'Blood samples'])
            ->assertOk()
            ->assertJsonPath('name', 'Blood samples');
    }

    public function test_renaming_to_another_entrys_name_is_rejected(): void
    {
        JobContentType::factory()->create(['name' => 'Milk']);
        $entry = JobContentType::factory()->create(['name' => 'Plasma']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['name' => 'MILK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('Plasma', $entry->fresh()->name);
    }

    public function test_a_trustee_deactivates_and_reactivates_an_entry(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'Plasma']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('name', 'Plasma');

        $this->assertFalse($entry->fresh()->active);

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['active' => true])
            ->assertOk()
            ->assertJsonPath('active', true);
    }

    public function test_a_trustee_can_reorder_an_entry(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'Plasma', 'sort_order' => 9]);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['sortOrder' => 1])
            ->assertOk()
            ->assertJsonPath('sortOrder', 1);
    }

    public function test_update_needs_something_to_change_and_rejects_a_blank_name(): void
    {
        $entry = JobContentType::factory()->create(['name' => 'Plasma']);

        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withToken('valid-token')
            ->patchJson("/api/job-contents/{$entry->id}", ['name' => '  ', 'active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('Plasma', $entry->fresh()->name);
        $this->assertTrue($entry->fresh()->active);
    }

    public function test_updating_an_unknown_or_malformed_id_is_not_found(): void
    {
        $this->fakeDirectory($this->volunteer('Trustee'));
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson('/api/job-contents/00000000-0000-4000-8000-000000000000', ['active' => false])
            ->assertNotFound();

        $this->withToken('valid-token')
            ->patchJson('/api/job-contents/not-a-uuid', ['active' => false])
            ->assertNotFound();
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
     * @param  array<string, mixed>  $volunteer
     */
    private function fakeDirectory(array $volunteer): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => [$volunteer]]),
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
            'name' => 'Test User',
        ], $overrides), fn ($value) => $value !== null);

        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->with('valid-token')
                ->andReturn($claims);
        });
    }
}
