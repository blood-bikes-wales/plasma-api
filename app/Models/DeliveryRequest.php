<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\DeliveryRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'id',
    'reference',
    'status',
    'job_type',
    'is_bereavement',
    'sender_party_id',
    'content_type_id',
    'content_label_snapshot',
    'scheduled_collection_at',
    'created_by_user_id',
    'recurrence_id',
    'occurrence_date',
])]
class DeliveryRequest extends Model
{
    /** @use HasFactory<DeliveryRequestFactory> */
    use HasFactory, HasUuidPrimaryKey;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'job_type' => 'standard',
        'is_bereavement' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'job_type' => JobType::class,
            'is_bereavement' => 'boolean',
            'scheduled_collection_at' => 'datetime',
            'occurrence_date' => 'date',
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
     * @return BelongsTo<JobRecurrence, $this>
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(JobRecurrence::class, 'recurrence_id');
    }

    /**
     * @return BelongsTo<JobContentType, $this>
     */
    public function contentType(): BelongsTo
    {
        return $this->belongsTo(JobContentType::class, 'content_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasOne<MilkJobDetail, $this>
     */
    public function milkDetail(): HasOne
    {
        return $this->hasOne(MilkJobDetail::class, 'delivery_request_id');
    }

    /**
     * @return HasMany<DeliveryLeg, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(DeliveryLeg::class, 'request_id')->orderBy('leg_number');
    }

    /**
     * @return BelongsToMany<ServiceArea, $this>
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceArea::class,
            'delivery_request_service_areas',
            'delivery_request_id',
            'service_area_id',
        );
    }

    public function isRelay(): bool
    {
        if ($this->relationLoaded('legs')) {
            return $this->legs->count() > 1;
        }

        return $this->legs()->count() > 1;
    }

    public function needsMotherContact(): bool
    {
        return $this->job_type === JobType::Milk
            && $this->status === JobStatus::New
            && $this->scheduled_collection_at === null;
    }

    /**
     * Hands each loaded leg its request (as parentJob for waitingForLeg), whose `legs` are the
     * sibling set.
     */
    public function linkLegsToParent(): static
    {
        if (! $this->relationLoaded('legs')) {
            return $this;
        }

        $this->legs->each(function (DeliveryLeg $leg): void {
            $leg->setRelation('request', $this);
        });

        return $this;
    }

    /**
     * @return list<string>
     */
    public function allowedActionNames(): array
    {
        if ($this->status === JobStatus::Cancelled || $this->status === JobStatus::Delivered) {
            return [];
        }

        if (! $this->isRelay()) {
            return [];
        }

        return [JobAction::Cancel->value];
    }

    public function waitingForLeg(): ?int
    {
        return null;
    }

    /**
     * Overall endpoints for a relay parent resource: first leg collection, last leg delivery.
     *
     * @return array{0: ?DeliveryLeg, 1: ?DeliveryLeg}
     */
    public function endpointLegs(): array
    {
        /** @var Collection<int, DeliveryLeg> $legs */
        $legs = $this->relationLoaded('legs') ? $this->legs : $this->legs()->get();

        if ($legs->isEmpty()) {
            return [null, null];
        }

        return [$legs->first(), $legs->last()];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query;
    }
}
