<?php

namespace App\Models;

use App\Enums\JobType;
use App\Enums\RecurrenceFrequency;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\JobRecurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'job_type',
    'is_bereavement',
    'sender_party_id',
    'content_type_id',
    'content_label_snapshot',
    'collection_place_id',
    'delivery_place_id',
    'collection_notes',
    'delivery_notes',
    'timezone',
    'time_of_day',
    'frequency',
    'by_weekday',
    'starts_on',
    'ends_on',
    'is_active',
    'horizon_days',
    'created_by_user_id',
])]
class JobRecurrence extends Model
{
    /** @use HasFactory<JobRecurrenceFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'job_type' => 'standard',
        'is_bereavement' => false,
        'timezone' => 'Europe/London',
        'is_active' => true,
        'horizon_days' => 14,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'is_bereavement' => 'boolean',
            'frequency' => RecurrenceFrequency::class,
            'by_weekday' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'horizon_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function senderParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'sender_party_id');
    }

    /**
     * @return BelongsTo<JobContentType, $this>
     */
    public function contentType(): BelongsTo
    {
        return $this->belongsTo(JobContentType::class, 'content_type_id');
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasOne<MilkRecurrenceDetail, $this>
     */
    public function milkDetail(): HasOne
    {
        return $this->hasOne(MilkRecurrenceDetail::class, 'job_recurrence_id');
    }

    /**
     * @return HasMany<DeliveryRequest, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(DeliveryRequest::class, 'recurrence_id');
    }

    /**
     * @return BelongsToMany<ServiceArea, $this>
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceArea::class,
            'job_recurrence_service_areas',
            'job_recurrence_id',
            'service_area_id',
        );
    }
}
