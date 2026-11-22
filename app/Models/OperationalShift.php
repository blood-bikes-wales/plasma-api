<?php

namespace App\Models;

use App\Enums\BikeKeptAt;
use App\Enums\MileageReadingKind;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\OperationalShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'rider_id',
    'bike_id',
    'shift_type',
    'logged_on_at',
    'logged_on_by_user_id',
    'logged_off_at',
    'faults',
    'bike_kept_at',
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
            'logged_on_at' => 'datetime',
            'logged_off_at' => 'datetime',
            'bike_kept_at' => BikeKeptAt::class,
        ];
    }

    /**
     * @return BelongsTo<Rider, $this>
     */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
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
     * @return HasMany<MileageReading, $this>
     */
    public function mileageReadings(): HasMany
    {
        return $this->hasMany(MileageReading::class);
    }

    /**
     * @return HasOne<MileageReading, $this>
     */
    public function startMileageReading(): HasOne
    {
        return $this->hasOne(MileageReading::class)
            ->where('kind', MileageReadingKind::Start);
    }

    /**
     * @return HasOne<MileageReading, $this>
     */
    public function endMileageReading(): HasOne
    {
        return $this->hasOne(MileageReading::class)
            ->where('kind', MileageReadingKind::End);
    }

    public function startMileage(): ?int
    {
        return $this->startMileageReading?->mileage;
    }

    public function endMileage(): ?int
    {
        return $this->endMileageReading?->mileage;
    }

    public function mileageVarianceReason(): ?string
    {
        return $this->startMileageReading?->reason;
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
