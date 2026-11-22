<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone');
            $table->string('phone_secondary')->nullable();
            $table->string('organisation')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->string('status');
            $table->string('job_type')->default('standard');
            $table->boolean('is_bereavement')->default(false);
            $table->foreignUuid('sender_party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('content_type_id')
                ->nullable()
                ->constrained('job_content_types')
                ->nullOnDelete();
            $table->text('content_label_snapshot');
            $table->timestamp('scheduled_collection_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('updated_at');
        });

        Schema::create('milk_job_details', function (Blueprint $table) {
            $table->foreignUuid('delivery_request_id')
                ->primary()
                ->constrained('delivery_requests')
                ->cascadeOnDelete();
            $table->decimal('milk_litres', 5, 2);
            $table->foreignUuid('mother_party_id')->constrained('parties')->restrictOnDelete();
        });

        Schema::create('delivery_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('delivery_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('leg_number');
            $table->string('reference')->unique();
            $table->string('status');
            $table->foreignUuid('collection_place_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('delivery_place_id')->constrained('places')->restrictOnDelete();
            $table->text('collection_notes')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamp('estimated_collection_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('planned_arrival_at')->nullable();
            $table->foreignUuid('operational_shift_id')
                ->nullable()
                ->constrained('operational_shifts')
                ->nullOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->boolean('contents_confirmed')->nullable();
            $table->string('receipt_number')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('recipient')->nullable();
            $table->boolean('no_recipient')->default(false);
            $table->timestamp('delivered_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'leg_number']);
            $table->index('updated_at');
            $table->index('delivered_at');
            $table->index('cancelled_at');
        });

        Schema::create('delivery_request_service_areas', function (Blueprint $table) {
            $table->foreignUuid('delivery_request_id')
                ->constrained('delivery_requests')
                ->cascadeOnDelete();
            $table->foreignUuid('service_area_id')
                ->constrained('service_areas')
                ->restrictOnDelete();
            $table->primary(['delivery_request_id', 'service_area_id'], 'delivery_request_service_areas_pk');
        });

        MigrationHelper::setUuidPrimaryKeyDefault('parties');
        MigrationHelper::setUuidPrimaryKeyDefault('delivery_requests');
        MigrationHelper::setUuidPrimaryKeyDefault('delivery_legs');

        $this->backfillFromDeliveryJobs();

        Schema::dropIfExists('delivery_job_service_areas');
        Schema::dropIfExists('delivery_jobs');
    }

    public function down(): void
    {
        Schema::create('delivery_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->string('status');
            $table->string('job_type')->default('standard');
            $table->decimal('milk_litres', 5, 2)->nullable();
            $table->boolean('is_bereavement')->default(false);
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('sender_organisation')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('mother_phone')->nullable();
            $table->string('mother_phone_secondary')->nullable();
            $table->foreignUuid('collection_place_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('delivery_place_id')->constrained('places')->restrictOnDelete();
            $table->text('collection_notes')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamp('estimated_collection_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('planned_arrival_at')->nullable();
            $table->timestamp('scheduled_collection_at')->nullable();
            $table->text('contents');
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('operational_shift_id')
                ->nullable()
                ->constrained('operational_shifts')
                ->nullOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->boolean('contents_confirmed')->nullable();
            $table->string('receipt_number')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('recipient')->nullable();
            $table->boolean('no_recipient')->default(false);
            $table->timestamp('delivered_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('parent_job_id')->nullable();
            $table->unsignedSmallInteger('leg_number')->nullable();
            $table->timestamps();

            $table->unique(['parent_job_id', 'leg_number']);
            $table->index('updated_at');
            $table->index('delivered_at');
            $table->index('cancelled_at');
        });

        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->foreign('parent_job_id')
                ->references('id')
                ->on('delivery_jobs')
                ->cascadeOnDelete();
        });

        Schema::create('delivery_job_service_areas', function (Blueprint $table) {
            $table->foreignUuid('delivery_job_id')
                ->constrained('delivery_jobs')
                ->cascadeOnDelete();
            $table->foreignUuid('service_area_id')
                ->constrained('service_areas')
                ->restrictOnDelete();
            $table->primary(['delivery_job_id', 'service_area_id']);
        });

        MigrationHelper::setUuidPrimaryKeyDefault('delivery_jobs');

        $this->restoreDeliveryJobs();

        Schema::dropIfExists('delivery_request_service_areas');
        Schema::dropIfExists('delivery_legs');
        Schema::dropIfExists('milk_job_details');
        Schema::dropIfExists('delivery_requests');
        Schema::dropIfExists('parties');
    }

    private function backfillFromDeliveryJobs(): void
    {
        if (! Schema::hasTable('delivery_jobs')) {
            return;
        }

        $contentTypeIdsByName = DB::table('job_content_types')
            ->pluck('id', 'name')
            ->all();

        $topLevel = DB::table('delivery_jobs')
            ->whereNull('parent_job_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($topLevel as $job) {
            $senderPartyId = (string) Str::uuid();
            $now = now();

            DB::table('parties')->insert([
                'id' => $senderPartyId,
                'name' => $job->sender_name,
                'phone' => $job->sender_phone,
                'phone_secondary' => null,
                'organisation' => $job->sender_organisation,
                'created_at' => $job->created_at ?? $now,
                'updated_at' => $job->updated_at ?? $now,
            ]);

            $contentTypeId = $contentTypeIdsByName[$job->contents] ?? null;

            DB::table('delivery_requests')->insert([
                'id' => $job->id,
                'reference' => $this->baseReference((string) $job->reference),
                'status' => $job->status,
                'job_type' => $job->job_type ?? 'standard',
                'is_bereavement' => (bool) ($job->is_bereavement ?? false),
                'sender_party_id' => $senderPartyId,
                'content_type_id' => $contentTypeId,
                'content_label_snapshot' => $job->contents,
                'scheduled_collection_at' => $job->scheduled_collection_at ?? null,
                'created_by_user_id' => $job->created_by_user_id,
                'created_at' => $job->created_at ?? $now,
                'updated_at' => $job->updated_at ?? $now,
            ]);

            if (($job->job_type ?? 'standard') === 'milk') {
                $motherPartyId = (string) Str::uuid();
                DB::table('parties')->insert([
                    'id' => $motherPartyId,
                    'name' => $job->mother_name ?? 'Unknown',
                    'phone' => $job->mother_phone ?? '',
                    'phone_secondary' => $job->mother_phone_secondary,
                    'organisation' => null,
                    'created_at' => $job->created_at ?? $now,
                    'updated_at' => $job->updated_at ?? $now,
                ]);

                DB::table('milk_job_details')->insert([
                    'delivery_request_id' => $job->id,
                    'milk_litres' => $job->milk_litres ?? 0,
                    'mother_party_id' => $motherPartyId,
                ]);
            }

            $areaIds = DB::table('delivery_job_service_areas')
                ->where('delivery_job_id', $job->id)
                ->pluck('service_area_id')
                ->all();

            if ($areaIds !== []) {
                DB::table('delivery_request_service_areas')->insert(array_map(
                    static fn ($areaId): array => [
                        'delivery_request_id' => $job->id,
                        'service_area_id' => $areaId,
                    ],
                    $areaIds,
                ));
            }

            $childLegs = DB::table('delivery_jobs')
                ->where('parent_job_id', $job->id)
                ->orderBy('leg_number')
                ->get();

            if ($childLegs->isEmpty()) {
                $this->insertLegFromJob($job, $job->id, 1, (string) $job->reference);

                continue;
            }

            $childLegs->each(function (object $leg) use ($job): void {
                $this->insertLegFromJob($leg, $job->id, (int) $leg->leg_number, (string) $leg->reference);
            });
        }
    }

    private function insertLegFromJob(object $job, string $requestId, int $legNumber, string $reference): void
    {
        $now = now();

        DB::table('delivery_legs')->insert([
            'id' => $job->id,
            'request_id' => $requestId,
            'leg_number' => $legNumber,
            'reference' => $reference,
            'status' => $job->status,
            'collection_place_id' => $job->collection_place_id,
            'delivery_place_id' => $job->delivery_place_id,
            'collection_notes' => $job->collection_notes ?? null,
            'delivery_notes' => $job->delivery_notes ?? null,
            'estimated_collection_at' => $job->estimated_collection_at ?? null,
            'estimated_delivery_at' => $job->estimated_delivery_at ?? null,
            'planned_arrival_at' => $job->planned_arrival_at ?? null,
            'operational_shift_id' => $job->operational_shift_id,
            'allocated_at' => $job->allocated_at,
            'contents_confirmed' => $job->contents_confirmed,
            'receipt_number' => $job->receipt_number,
            'collected_at' => $job->collected_at,
            'recipient' => $job->recipient,
            'no_recipient' => (bool) ($job->no_recipient ?? false),
            'delivered_at' => $job->delivered_at,
            'cancellation_reason' => $job->cancellation_reason,
            'cancelled_at' => $job->cancelled_at,
            'created_at' => $job->created_at ?? $now,
            'updated_at' => $job->updated_at ?? $now,
        ]);
    }

    private function baseReference(string $reference): string
    {
        if (preg_match('/^(.*)-L\d+$/', $reference, $matches) === 1) {
            return $matches[1];
        }

        return $reference;
    }

    private function restoreDeliveryJobs(): void
    {
        $requests = DB::table('delivery_requests')->orderBy('created_at')->get();

        foreach ($requests as $request) {
            $sender = DB::table('parties')->where('id', $request->sender_party_id)->first();
            $milk = DB::table('milk_job_details')->where('delivery_request_id', $request->id)->first();
            $mother = $milk === null
                ? null
                : DB::table('parties')->where('id', $milk->mother_party_id)->first();

            $legs = DB::table('delivery_legs')
                ->where('request_id', $request->id)
                ->orderBy('leg_number')
                ->get();

            $isRelay = $legs->count() > 1;
            $firstLeg = $legs->first();

            if ($firstLeg === null) {
                continue;
            }

            // Relay parent row (request id) — uses first/last leg places as overall endpoints.
            if ($isRelay) {
                $lastLeg = $legs->last();
                DB::table('delivery_jobs')->insert([
                    'id' => $request->id,
                    'reference' => $request->reference,
                    'status' => $request->status,
                    'job_type' => $request->job_type,
                    'milk_litres' => $milk?->milk_litres,
                    'is_bereavement' => $request->is_bereavement,
                    'sender_name' => $sender?->name ?? '',
                    'sender_phone' => $sender?->phone ?? '',
                    'sender_organisation' => $sender?->organisation,
                    'mother_name' => $mother?->name,
                    'mother_phone' => $mother?->phone,
                    'mother_phone_secondary' => $mother?->phone_secondary,
                    'collection_place_id' => $firstLeg->collection_place_id,
                    'delivery_place_id' => $lastLeg->delivery_place_id,
                    'collection_notes' => null,
                    'delivery_notes' => null,
                    'estimated_collection_at' => null,
                    'estimated_delivery_at' => null,
                    'planned_arrival_at' => null,
                    'scheduled_collection_at' => $request->scheduled_collection_at,
                    'contents' => $request->content_label_snapshot,
                    'created_by_user_id' => $request->created_by_user_id,
                    'operational_shift_id' => null,
                    'allocated_at' => null,
                    'contents_confirmed' => null,
                    'receipt_number' => null,
                    'collected_at' => null,
                    'recipient' => null,
                    'no_recipient' => false,
                    'delivered_at' => null,
                    'cancellation_reason' => null,
                    'cancelled_at' => null,
                    'parent_job_id' => null,
                    'leg_number' => null,
                    'created_at' => $request->created_at,
                    'updated_at' => $request->updated_at,
                ]);
            }

            $legs->each(function (object $leg) use ($request, $sender, $milk, $mother, $isRelay): void {
                $this->restoreJobRow($request, $leg, $sender, $milk, $mother, $isRelay);
            });

            $this->restoreServiceAreas($request->id, $legs, $isRelay);
        }
    }

    private function restoreJobRow(
        object $request,
        object $leg,
        ?object $sender,
        ?object $milk,
        ?object $mother,
        bool $isRelay,
    ): void {
        $parentId = $isRelay ? $request->id : null;
        $legNumber = $isRelay ? $leg->leg_number : null;
        $rowId = $isRelay ? $leg->id : $request->id;

        DB::table('delivery_jobs')->insert([
            'id' => $rowId,
            'reference' => $isRelay ? $leg->reference : $request->reference,
            'status' => $isRelay ? $leg->status : $request->status,
            'job_type' => $request->job_type,
            'milk_litres' => $milk?->milk_litres,
            'is_bereavement' => $request->is_bereavement,
            'sender_name' => $sender?->name ?? '',
            'sender_phone' => $sender?->phone ?? '',
            'sender_organisation' => $sender?->organisation,
            'mother_name' => $mother?->name,
            'mother_phone' => $mother?->phone,
            'mother_phone_secondary' => $mother?->phone_secondary,
            'collection_place_id' => $leg->collection_place_id,
            'delivery_place_id' => $leg->delivery_place_id,
            'collection_notes' => $leg->collection_notes,
            'delivery_notes' => $leg->delivery_notes,
            'estimated_collection_at' => $leg->estimated_collection_at,
            'estimated_delivery_at' => $leg->estimated_delivery_at,
            'planned_arrival_at' => $leg->planned_arrival_at,
            'scheduled_collection_at' => $request->scheduled_collection_at,
            'contents' => $request->content_label_snapshot,
            'created_by_user_id' => $request->created_by_user_id,
            'operational_shift_id' => $leg->operational_shift_id,
            'allocated_at' => $leg->allocated_at,
            'contents_confirmed' => $leg->contents_confirmed,
            'receipt_number' => $leg->receipt_number,
            'collected_at' => $leg->collected_at,
            'recipient' => $leg->recipient,
            'no_recipient' => $leg->no_recipient,
            'delivered_at' => $leg->delivered_at,
            'cancellation_reason' => $leg->cancellation_reason,
            'cancelled_at' => $leg->cancelled_at,
            'parent_job_id' => $parentId,
            'leg_number' => $legNumber,
            'created_at' => $leg->created_at,
            'updated_at' => $leg->updated_at,
        ]);
    }

    private function restoreServiceAreas(string $requestId, $legs, bool $isRelay): void
    {
        $areaIds = DB::table('delivery_request_service_areas')
            ->where('delivery_request_id', $requestId)
            ->pluck('service_area_id')
            ->all();

        if ($areaIds === []) {
            return;
        }

        $jobIds = $isRelay ? [...$legs->pluck('id')->all(), $requestId] : [$requestId];

        $rows = array_merge([], ...array_map(
            static fn (string $jobId): array => array_map(
                static fn ($areaId): array => [
                    'delivery_job_id' => $jobId,
                    'service_area_id' => $areaId,
                ],
                $areaIds,
            ),
            $jobIds,
        ));

        DB::table('delivery_job_service_areas')->insert($rows);
    }
};
