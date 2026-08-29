<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->foreignUuid('operational_shift_id')
                ->nullable()
                ->constrained('operational_shifts')
                ->nullOnDelete();
            $table->unsignedBigInteger('allocated_rider_id')->nullable();
            $table->string('allocated_rider_name')->nullable();
            $table->timestamp('allocated_at')->nullable();

            $table->boolean('contents_confirmed')->nullable();
            $table->boolean('suitably_sealed')->nullable();
            $table->string('seal_number')->nullable();
            $table->string('receipt_number')->nullable();
            $table->timestamp('collected_at')->nullable();

            $table->string('recipient')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operational_shift_id');

            $table->dropColumn([
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
            ]);
        });
    }
};
