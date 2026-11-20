<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Users\UserSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInVolunteers;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase, SignsInVolunteers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useThreeRingsConfig();
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->patchJson('/api/me', ['showCompletedJobs' => true])->assertUnauthorized();
    }

    public function test_a_new_user_defaults_to_hiding_old_completed_jobs_without_a_settings_row(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('settings.showCompletedJobs', false);

        $this->assertSame(0, UserSetting::query()->count());
    }

    public function test_patching_me_saves_the_setting_and_returns_the_updated_profile(): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson('/api/me', ['showCompletedJobs' => true])
            ->assertOk()
            ->assertJsonPath('email', 'rider@bloodbikes.wales')
            ->assertJsonPath('roles', ['controller'])
            ->assertJsonPath('settings.showCompletedJobs', true);

        $this->withToken('valid-token')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('settings.showCompletedJobs', true);

        $this->withToken('valid-token')
            ->patchJson('/api/me', ['showCompletedJobs' => false])
            ->assertOk()
            ->assertJsonPath('settings.showCompletedJobs', false);

        $this->withToken('valid-token')
            ->getJson('/api/me')
            ->assertJsonPath('settings.showCompletedJobs', false);

        $this->assertSame(1, UserSetting::query()->count(), 'One row per user, updated in place.');
    }

    public function test_patching_me_only_changes_the_signed_in_user(): void
    {
        $other = User::factory()->create(['email' => 'other@bloodbikes.wales']);
        $otherSetting = UserSetting::query()->create(['user_id' => $other->id, 'show_completed_jobs' => false]);
        $bystander = User::factory()->create(['email' => 'bystander@bloodbikes.wales']);

        $this->fakeDirectory([
            $this->volunteer('Controller'),
            $this->volunteer('Controller', 'other@bloodbikes.wales', 100002),
        ]);
        $this->mockVerifiedClaims();

        // An id in the body must not redirect the write to another user.
        $this->withToken('valid-token')
            ->patchJson('/api/me', [
                'showCompletedJobs' => true,
                'user_id' => $other->id,
                'id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('email', 'rider@bloodbikes.wales')
            ->assertJsonPath('settings.showCompletedJobs', true);

        $this->assertFalse($otherSetting->refresh()->show_completed_jobs);
        $this->assertNull($bystander->settings);
        $this->assertSame(2, UserSetting::query()->count());
        $this->assertTrue(
            UserSetting::query()->where('user_id', User::query()->where('email', 'rider@bloodbikes.wales')->value('id'))->value('show_completed_jobs'),
        );
    }

    public function test_a_second_controllers_setting_is_independent(): void
    {
        $this->fakeDirectory([
            $this->volunteer('Controller'),
            $this->volunteer('Controller', 'other@bloodbikes.wales', 100002),
        ]);
        $this->mockVerifiedClaims();
        $this->withToken('valid-token')->patchJson('/api/me', ['showCompletedJobs' => true])->assertOk();

        $this->mockVerifiedClaims('other@bloodbikes.wales', 'google-sub-456');
        $this->withToken('valid-token')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', 'other@bloodbikes.wales')
            ->assertJsonPath('settings.showCompletedJobs', false);
    }

    #[DataProvider('everyRole')]
    public function test_any_signed_in_user_can_change_their_own_setting(string $role): void
    {
        $this->fakeDirectory([$this->volunteer($role)]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson('/api/me', ['showCompletedJobs' => true])
            ->assertOk()
            ->assertJsonPath('settings.showCompletedJobs', true);
    }

    public function test_a_signed_in_user_with_no_roles_can_read_and_change_their_own_setting(): void
    {
        $this->fakeDirectory([]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson('/api/me', ['showCompletedJobs' => true])
            ->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath('settings.showCompletedJobs', true);
    }

    #[DataProvider('invalidPayloads')]
    public function test_the_payload_is_validated(array $payload): void
    {
        $this->fakeDirectory([$this->volunteer('Controller')]);
        $this->mockVerifiedClaims();

        $this->withToken('valid-token')
            ->patchJson('/api/me', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('showCompletedJobs');

        $this->assertSame(0, UserSetting::query()->count());
    }

    public function test_the_first_write_is_an_upsert_that_cannot_lose_a_race(): void
    {
        $user = User::factory()->create();
        $service = app(UserSettingsService::class);

        // Another request wins the race and inserts the row after we decided there was none.
        UserSetting::query()->create(['user_id' => $user->id, 'show_completed_jobs' => false]);
        $stale = User::query()->findOrFail($user->id);

        try {
            $service->updateShowCompletedJobs($stale, true);
        } catch (UniqueConstraintViolationException) {
            $this->fail('A concurrent first write must update, not violate the unique index.');
        }

        $this->assertSame(1, UserSetting::query()->where('user_id', $user->id)->count());
        $this->assertTrue($service->showCompletedJobs(User::query()->findOrFail($user->id)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyRole(): array
    {
        return [
            'controller' => ['Controller'],
            'rider' => ['Rider'],
            'driver' => ['Driver'],
            'trustee' => ['Trustee'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing' => [[]],
            'null' => [['showCompletedJobs' => null]],
            'a word' => [['showCompletedJobs' => 'maybe']],
            'an array' => [['showCompletedJobs' => []]],
        ];
    }
}
