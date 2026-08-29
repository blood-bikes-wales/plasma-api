<?php

namespace App\Models;

use App\Enums\JobStatus;
use Database\Factories\DeliveryJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
