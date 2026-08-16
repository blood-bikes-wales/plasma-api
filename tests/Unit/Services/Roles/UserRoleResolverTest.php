<?php

namespace Tests\Unit\Services\Roles;

use App\Enums\Role;
use App\Models\User;
use App\Services\Roles\UserRoleResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserRoleResolverTest extends TestCase
{
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

    public function test_maps_three_rings_roles_and_ignores_unknown_names(): void
    {
        $this->fakeDirectory([
            [
                'id' => 1,
                'name' => 'Test Rider',
                'roles' => [
                    ['role' => ['id' => 1, 'name' => 'Everyone']],
                    ['role' => ['id' => 2, 'name' => 'Controller']],
                    ['role' => ['id' => 3, 'name' => 'Rider']],
                    ['role' => ['id' => 4, 'name' => 'Statistics']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                ],
            ],
        ]);

        $user = new User(['email' => 'rider@bloodbikes.wales', 'is_admin' => false]);
        $roles = $this->resolver()->forUser($user);

        $this->assertSame([Role::Controller, Role::Rider], $roles);
    }

    public function test_email_match_is_case_insensitive(): void
    {
        $this->fakeDirectory([
            [
                'id' => 2,
                'name' => 'Test',
                'roles' => [
                    ['role' => ['id' => 3, 'name' => 'Driver']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'rider@bloodbikes.wales']],
                ],
            ],
        ]);

        $user = new User(['email' => 'Rider@BloodBikes.Wales', 'is_admin' => false]);
        $roles = $this->resolver()->forUser($user);

        $this->assertSame([Role::Driver], $roles);
    }

    public function test_admin_flag_prepends_admin_role(): void
    {
        $this->fakeDirectory([
            [
                'id' => 3,
                'name' => 'Admin',
                'roles' => [
                    ['role' => ['id' => 2, 'name' => 'Controller']],
                ],
                'volunteer_properties' => [
                    ['volunteer_property' => ['name' => 'Email', 'value' => 'admin@bloodbikes.wales']],
                ],
            ],
        ]);

        $user = new User(['email' => 'admin@bloodbikes.wales', 'is_admin' => true]);
        $roles = $this->resolver()->forUser($user);

        $this->assertSame([Role::Admin, Role::Controller], $roles);
    }

    public function test_missing_directory_match_returns_empty_or_admin_only(): void
    {
        $this->fakeDirectory([]);

        $resolver = $this->resolver();
        $user = new User(['email' => 'unknown@bloodbikes.wales', 'is_admin' => false]);
        $admin = new User(['email' => 'unknown@bloodbikes.wales', 'is_admin' => true]);

        $this->assertSame([], $resolver->forUser($user));
        $this->assertSame([Role::Admin], $resolver->forUser($admin));
    }

    public function test_three_rings_outage_returns_admin_only_without_failing(): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response('unavailable', 503),
        ]);

        $user = new User(['email' => 'rider@bloodbikes.wales', 'is_admin' => true]);
        $roles = $this->resolver()->forUser($user);

        $this->assertSame([Role::Admin], $roles);
    }

    private function resolver(): UserRoleResolver
    {
        return $this->app->make(UserRoleResolver::class);
    }

    /**
     * @param  list<array<string, mixed>>  $volunteers
     */
    private function fakeDirectory(array $volunteers): void
    {
        Http::fake([
            'www.3r.org.uk/directory.json*' => Http::response(['volunteers' => $volunteers]),
        ]);
    }
}
