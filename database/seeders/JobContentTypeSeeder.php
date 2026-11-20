<?php

namespace Database\Seeders;

use App\Models\JobContentType;
use Illuminate\Database\Seeder;

/**
 * Starter "contents" list for New Job. The migration seeds the same list for
 * existing environments; this keeps `db:seed` (local, fresh databases) in step.
 * Idempotent: an existing entry (any case) is left alone, so trustee edits survive.
 *
 * The list is a proposal and must be confirmed before release.
 */
class JobContentTypeSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const array CONTENTS = [
        'Blood samples',
        'Milk',
        'Platelets',
        'Plasma',
        'Urgent medication',
        'Documents',
        'Equipment',
    ];

    public function run(): void
    {
        foreach (self::CONTENTS as $position => $name) {
            $exists = JobContentType::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($exists) {
                continue;
            }

            JobContentType::query()->create([
                'name' => $name,
                'active' => true,
                'sort_order' => $position + 1,
            ]);
        }
    }
}
