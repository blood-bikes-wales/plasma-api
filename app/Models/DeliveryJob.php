<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\DeliveryJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'status',
    'sender_name',
    'sender_phone',
    'sender_organisation',
    'collection_place_id',
    'delivery_place_id',
    'contents',
    'created_by_user_id',
    'operational_shift_id',
    'allocated_at',
    'contents_confirmed',
    'suitably_sealed',
    'seal_number',
    'receipt_number',
    'collected_at',
    'recipient',
    'delivered_at',
    'cancellation_reason',
    'cancelled_at',
    'parent_job_id',
    'leg_number',
])]
class DeliveryJob extends Model
{
    /** @use HasFactory<DeliveryJobFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'contents_confirmed' => 'boolean',
            'suitably_sealed' => 'boolean',
            'leg_number' => 'integer',
            'allocated_at' => 'datetime',
            'collected_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<OperationalShift, $this>
     */
    public function operationalShift(): BelongsTo
    {
        return $this->belongsTo(OperationalShift::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function collectionPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'collection_place_id');
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function deliveryPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'delivery_place_id');
    }

    /**
     * @return BelongsToMany<ServiceArea, $this>
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceArea::class,
            'delivery_job_service_areas',
            'delivery_job_id',
            'service_area_id',
        );
    }

    /**
     * @return BelongsTo<DeliveryJob, $this>
     */
    public function parentJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_job_id');
    }

    /**
     * @return HasMany<DeliveryJob, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(self::class, 'parent_job_id')->orderBy('leg_number');
    }

    public function isRelayParent(): bool
    {
        if ($this->relationLoaded('legs')) {
            return $this->legs->isNotEmpty();
        }

        return $this->legs()->exists();
    }

    public function isRelayLeg(): bool
    {
        return $this->parent_job_id !== null;
    }

    /**
     * @return list<string>
     */
    public function allowedActionNames(): array
    {
        if ($this->isRelayParent()) {
            if ($this->status === JobStatus::Cancelled || $this->status === JobStatus::Delivered) {
                return [];
            }

            return [JobAction::Cancel->value];
        }

        $actions = array_map(
            static fn (JobAction $action): string => $action->value,
            JobAction::forStatus($this->status),
        );

        if ($this->parent_job_id === null && $this->status === JobStatus::New) {
            $actions[] = 'relay';
        }

        return $actions;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_job_id');
    }
}
