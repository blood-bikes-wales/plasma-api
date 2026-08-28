<?php

namespace App\Models;

use Database\Factories\MileageReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bike_id',
    'operational_shift_id',
    'mileage',
    'reason',
    'recorded_at',
    'recorded_by_user_id',
])]
class MileageReading extends Model
{
    /** @use HasFactory<MileageReadingFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bike, $this>
     */
    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    /**
     * @return BelongsTo<OperationalShift, $this>
     */
    public function operationalShift(): BelongsTo
    {
        return $this->belongsTo(OperationalShift::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
