<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Flip is_admin for known Plasma admin emails.
 *
 * Emails are read from ADMIN_EMAILS (comma-separated). Missing users are
 * created as placeholders so Google login can later link google_id by email.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $emails = collect(explode(',', (string) env('ADMIN_EMAILS', '')))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique();

        foreach ($emails as $email) {
            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => strstr($email, '@', true) ?: $email,
                    'is_admin' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
