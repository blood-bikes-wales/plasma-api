<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\OperationalShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'rider_id',
    'rider_name',
    'bike_id',
    'start_mileage',
    'mileage_variance_reason',
    'logged_on_at',
    'logged_on_by_user_id',
    'logged_off_at',
    'end_mileage',
    'faults',
    'logged_off_by_user_id',
])]
class OperationalShift extends Model
{
    /** @use HasFactory<OperationalShiftFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rider_id' => 'integer',
            'start_mileage' => 'integer',
            'end_mileage' => 'integer',
            'logged_on_at' => 'datetime',
            'logged_off_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function loggedOnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_on_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function loggedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_off_by_user_id');
    }

    /**
     * @return HasOne<MileageReading, $this>
     */
    public function mileageReading(): HasOne
    {
        return $this->hasOne(MileageReading::class);
    }

    public function isActive(): bool
    {
        return $this->logged_off_at === null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('logged_off_at');
    }
}
