<?php

use App\Support\Migration as MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Starter list so existing environments can create jobs as soon as this is
     * deployed. Trustees can rename, add and retire entries afterwards. Keep in
     * step with JobContentTypeSeeder (which is idempotent, so running both is safe).
     *
     * @var list<string>
     */
    private const array STARTER_CONTENTS = [
        'Blood samples',
        'Milk',
        'Platelets',
        'Plasma',
        'Urgent medication',
        'Documents',
        'Equipment',
    ];

    public function up(): void
    {
        Schema::create('job_content_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        MigrationHelper::setUuidPrimaryKeyDefault('job_content_types');

        // Names are unique ignoring case ("milk" and "Milk" are the same entry).
        // An expression index works on PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX job_content_types_name_lower_unique ON job_content_types (LOWER(name))');

        $now = now();

        foreach (self::STARTER_CONTENTS as $position => $name) {
            DB::table('job_content_types')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'active' => true,
                'sort_order' => $position + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_content_types');
    }
};
