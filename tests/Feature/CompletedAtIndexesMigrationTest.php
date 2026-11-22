<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Completed-job age filters use single-column indexes on the hop table (`delivery_legs`).
 */
class CompletedAtIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_legs_has_completed_at_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('delivery_legs'));
        $this->assertTrue(Schema::hasIndex('delivery_legs', ['updated_at']));
        $this->assertTrue(Schema::hasIndex('delivery_legs', ['delivered_at']));
        $this->assertTrue(Schema::hasIndex('delivery_legs', ['cancelled_at']));
    }
}
