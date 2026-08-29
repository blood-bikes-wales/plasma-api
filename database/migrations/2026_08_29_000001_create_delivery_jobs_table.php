<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delivery_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->string('status');
            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('sender_organisation')->nullable();
            $table->string('collection_place_id');
            $table->string('collection_address');
            $table->decimal('collection_latitude', 10, 7);
            $table->decimal('collection_longitude', 10, 7);
            $table->string('delivery_place_id');
            $table->string('delivery_address');
            $table->decimal('delivery_latitude', 10, 7);
            $table->decimal('delivery_longitude', 10, 7);
            $table->text('contents');
            $table->json('service_areas');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_jobs');
    }
};
