<?php

namespace App\Models;

use App\Enums\BikeStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\BikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registration',
    'area',
    'last_recorded_mileage',
    'status',
    'retired_at',
    'purchased_at',
])]
class Bike extends Model
{
    /** @use HasFactory<BikeFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_recorded_mileage' => 'integer',
            'retired_at' => 'datetime',
            'purchased_at' => 'date',
        ];
    }

    /**
     * @return HasMany<OperationalShift, $this>
     */
    public function operationalShifts(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }

    /**
     * @return HasMany<MileageReading, $this>
     */
    public function mileageReadings(): HasMany
    {
        return $this->hasMany(MileageReading::class);
    }

    public function isActive(): bool
    {
        return $this->status === BikeStatus::Active->value;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BikeStatus::Active->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', BikeStatus::Retired->value);
    }
}
