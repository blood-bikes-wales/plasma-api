<?php

namespace Tests\Feature;

use App\Models\JobContentType;
use Database\Seeders\JobContentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobContentStarterListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_seeds_the_starter_list_including_milk(): void
    {
        $names = JobContentType::query()->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(JobContentTypeSeeder::CONTENTS, $names);
        $this->assertContains('Milk', $names);
        $this->assertSame(count($names), JobContentType::query()->active()->count());
    }
}
