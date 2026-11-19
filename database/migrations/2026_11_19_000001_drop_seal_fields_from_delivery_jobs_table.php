<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Controllers no longer record whether a package was sealed or its seal
     * number. Existing values are discarded.
     */
    public function up(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->dropColumn(['suitably_sealed', 'seal_number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the empty columns; the discarded data cannot be recovered.
     */
    public function down(): void
    {
        Schema::table('delivery_jobs', function (Blueprint $table) {
            $table->boolean('suitably_sealed')->nullable()->after('contents_confirmed');
            $table->string('seal_number')->nullable()->after('suitably_sealed');
        });
    }
};
