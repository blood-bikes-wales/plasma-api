<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The completed-job indexes were first shipped as (status, delivered_at) / (status, cancelled_at)
 * in migration 000002 and replaced by single-column ones in 000003. Environments that already ran
 * 000002 (staging) only ever run 000003, so it has to work over the old indexes as well as fresh.
 */
class CompletedAtIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const array OLD = [
        'delivery_jobs_status_delivered_at_index',
        'delivery_jobs_status_cancelled_at_index',
    ];

    private const array NEW = [
        'delivery_jobs_updated_at_index',
        'delivery_jobs_delivered_at_index',
        'delivery_jobs_cancelled_at_index',
    ];

    public function test_a_fresh_migrate_ends_with_exactly_the_three_new_indexes(): void
    {
        $this->assertSame([], $this->completedIndexes(self::OLD));
        $this->assertSame($this->sorted(self::NEW), $this->completedIndexes(self::NEW));
    }

    public function test_the_replace_migration_over_a_table_that_already_has_the_old_indexes(): void
    {
        // Put the table in the state an environment is in after 000002 but before 000003.
        $this->migration('000003_replace_completed_at_indexes_on_delivery_jobs_table')->down();

        $this->assertSame($this->sorted(self::OLD), $this->completedIndexes(self::OLD));
        $this->assertSame([], $this->completedIndexes(self::NEW));

        $this->migration('000003_replace_completed_at_indexes_on_delivery_jobs_table')->up();

        $this->assertSame([], $this->completedIndexes(self::OLD));
        $this->assertSame($this->sorted(self::NEW), $this->completedIndexes(self::NEW));
    }

    public function test_the_replace_migration_can_be_run_twice(): void
    {
        $this->migration('000003_replace_completed_at_indexes_on_delivery_jobs_table')->up();

        $this->assertSame([], $this->completedIndexes(self::OLD));
        $this->assertSame($this->sorted(self::NEW), $this->completedIndexes(self::NEW));
    }

    public function test_rolling_both_back_leaves_none_of_them(): void
    {
        $this->migration('000003_replace_completed_at_indexes_on_delivery_jobs_table')->down();
        $this->migration('000002_add_completed_at_indexes_to_delivery_jobs_table')->down();

        $this->assertSame([], $this->completedIndexes([...self::OLD, ...self::NEW]));

        // And forward again from nothing.
        $this->migration('000002_add_completed_at_indexes_to_delivery_jobs_table')->up();
        $this->assertSame($this->sorted(self::OLD), $this->completedIndexes(self::OLD));
        $this->migration('000003_replace_completed_at_indexes_on_delivery_jobs_table')->up();
        $this->assertSame($this->sorted(self::NEW), $this->completedIndexes(self::NEW));
        $this->assertSame([], $this->completedIndexes(self::OLD));
    }

    private function migration(string $suffix): Migration
    {
        return require database_path("migrations/2026_12_04_{$suffix}.php");
    }

    /**
     * The names from $candidates that exist on delivery_jobs, sorted.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function completedIndexes(array $candidates): array
    {
        $existing = array_column(Schema::getIndexes('delivery_jobs'), 'name');

        return $this->sorted(array_values(array_intersect($candidates, $existing)));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
