<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use App\Enums\JobType;
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
    'job_type',
    'milk_litres',
    'is_bereavement',
    'sender_name',
    'sender_phone',
    'sender_organisation',
    'mother_name',
    'mother_phone',
    'mother_phone_secondary',
    'collection_place_id',
    'delivery_place_id',
    'collection_notes',
    'delivery_notes',
    'estimated_collection_at',
    'estimated_delivery_at',
    'planned_arrival_at',
    'scheduled_collection_at',
    'contents',
    'created_by_user_id',
    'operational_shift_id',
    'allocated_at',
    'contents_confirmed',
    'receipt_number',
    'collected_at',
    'recipient',
    'no_recipient',
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
     * Mirrors the column default so a freshly created model reads as a standard job.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'job_type' => 'standard',
        'is_bereavement' => false,
        'no_recipient' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'job_type' => JobType::class,
            'milk_litres' => 'float',
            'is_bereavement' => 'boolean',
            'contents_confirmed' => 'boolean',
            'no_recipient' => 'boolean',
            'leg_number' => 'integer',
            'estimated_collection_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
            'scheduled_collection_at' => 'datetime',
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
        // A leg is never itself a relay (legs cannot be converted again), so skip the per-leg `exists()` query.
        if ($this->parent_job_id !== null) {
            return false;
        }

        if ($this->relationLoaded('legs')) {
            return $this->legs->isNotEmpty();
        }

        return $this->legs()->exists();
    }

    /**
     * Whether the controller still has to phone the mother to arrange the collection: a milk job
     * that has not been picked up by anyone yet and has no scheduled time. A relay leg never asks;
     * the relay's top-level job does. Reads attributes only, so it costs no query per row.
     */
    public function needsMotherContact(): bool
    {
        return $this->parent_job_id === null
            && $this->job_type === JobType::Milk
            && $this->status === JobStatus::New
            && $this->scheduled_collection_at === null;
    }

    public function isRelayLeg(): bool
    {
        return $this->parent_job_id !== null;
    }

    /**
     * Hands each loaded leg its parent, whose `legs` are the sibling set, so asking a leg about the
     * previous leg (see waitingForLeg()) costs no further query. Call after `legs` is loaded.
     */
    public function linkLegsToParent(): static
    {
        if ($this->relationLoaded('legs')) {
            foreach ($this->legs as $leg) {
                $leg->setRelation('parentJob', $this);
            }
        }

        return $this;
    }

    /**
     * The leg that precedes this one on the relay: the sibling with the highest leg_number below
     * this leg's. Legs are numbered 1..n without gaps, so that is leg_number - 1; matching on the
     * number (not on collection order) keeps it correct however the siblings were loaded.
     * Reads `parentJob.legs`, which callers should have eager loaded.
     */
    public function previousLeg(): ?self
    {
        if ($this->parent_job_id === null || $this->leg_number === null || $this->leg_number <= 1) {
            return null;
        }

        $siblings = $this->parentJob?->legs;
        if ($siblings === null) {
            return null;
        }

        return $siblings
            ->filter(fn (self $sibling): bool => $sibling->leg_number !== null && $sibling->leg_number < $this->leg_number)
            ->sortByDesc('leg_number')
            ->first();
    }

    /**
     * The number of the earlier leg that must be delivered before this leg can be collected or
     * delivered, or null when nothing is holding it back. A previous leg that is Cancelled keeps
     * this leg blocked: the relay is cancelled as a whole through the parent.
     */
    public function waitingForLeg(): ?int
    {
        if (! in_array($this->status, [JobStatus::New, JobStatus::Allocated, JobStatus::Collected], true)) {
            return null;
        }

        $previous = $this->previousLeg();
        if ($previous === null || $previous->status === JobStatus::Delivered) {
            return null;
        }

        return $previous->leg_number;
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

        if ($this->waitingForLeg() !== null) {
            // Allocate stays available so riders can be lined up ahead of the leg before.
            $actions = array_values(array_filter(
                $actions,
                static fn (string $action): bool => $action !== JobAction::Collect->value && $action !== JobAction::Deliver->value,
            ));
        }

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
