<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\BikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'registration',
    'service_area_id',
    'initial_mileage',
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
            'initial_mileage' => 'integer',
            'retired_at' => 'datetime',
            'purchased_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ServiceArea, $this>
     */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /**
     * @return HasMany<OperationalShift, $this>
     */
    public function operationalShifts(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }

    /**
     * @return HasManyThrough<MileageReading, OperationalShift, $this>
     */
    public function mileageReadings(): HasManyThrough
    {
        return $this->hasManyThrough(
            MileageReading::class,
            OperationalShift::class,
            'bike_id',
            'operational_shift_id',
            'id',
            'id',
        );
    }

    public function isActive(): bool
    {
        return $this->retired_at === null;
    }

    public function currentMileage(): int
    {
        $latest = $this->relationLoaded('mileageReadings')
            ? $this->mileageReadings->sortByDesc('recorded_at')->first()
            : $this->mileageReadings()->orderByDesc('recorded_at')->first();

        if ($latest !== null) {
            return (int) $latest->mileage;
        }

        return (int) $this->initial_mileage;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }
}
