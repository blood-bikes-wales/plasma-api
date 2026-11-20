<?php

namespace App\Services\Users;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Str;

final class UserSettingsService
{
    /**
     * Whether the user wants completed jobs older than 24 hours listed too.
     * A user who has never saved a setting has no row, which means false.
     */
    public function showCompletedJobs(User $user): bool
    {
        return $user->settings?->show_completed_jobs ?? false;
    }

    /**
     * Change the user's own setting. One INSERT ... ON CONFLICT (user_id) DO UPDATE, so two
     * first writes racing each other cannot hit the unique index and fail; the loser just
     * updates. upsert() skips model events, so the uuid is generated here (the row keeps its
     * original id when it already exists).
     */
    public function updateShowCompletedJobs(User $user, bool $show): void
    {
        $now = now();

        UserSetting::query()->upsert(
            [[
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'show_completed_jobs' => $show,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['show_completed_jobs', 'updated_at'],
        );

        // The relation may have been read earlier in this request (or be cached as "no row").
        $user->unsetRelation('settings');
    }
}
