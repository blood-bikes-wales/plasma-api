<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of preferences per user. Absent row = defaults, see UserSettingsService.
 */
#[Fillable([
    'user_id',
    'show_completed_jobs',
])]
class UserSetting extends Model
{
    use HasUuidPrimaryKey;

    /**
     * Mirrors the column default so a freshly created model reads as the defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'show_completed_jobs' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_completed_jobs' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
