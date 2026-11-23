<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_recurrence_id',
    'milk_litres',
    'mother_party_id',
])]
class MilkRecurrenceDetail extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'job_recurrence_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'milk_litres' => 'float',
        ];
    }

    /**
     * @return BelongsTo<JobRecurrence, $this>
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(JobRecurrence::class, 'job_recurrence_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function motherParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'mother_party_id');
    }
}
