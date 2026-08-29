<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use Database\Factories\DeliveryJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'status',
    'sender_name',
    'sender_phone',
    'sender_organisation',
    'collection_place_id',
    'collection_address',
    'collection_latitude',
    'collection_longitude',
    'delivery_place_id',
    'delivery_address',
    'delivery_latitude',
    'delivery_longitude',
    'contents',
    'service_areas',
    'created_by_user_id',
    'operational_shift_id',
    'allocated_rider_id',
    'allocated_rider_name',
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
    'is_relay',
])]
class DeliveryJob extends Model
{
    /** @use HasFactory<DeliveryJobFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'collection_latitude' => 'float',
            'collection_longitude' => 'float',
            'delivery_latitude' => 'float',
            'delivery_longitude' => 'float',
            'service_areas' => 'array',
            'allocated_rider_id' => 'integer',
            'contents_confirmed' => 'boolean',
            'suitably_sealed' => 'boolean',
            'is_relay' => 'boolean',
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
        return (bool) $this->is_relay;
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
