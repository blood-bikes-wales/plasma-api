<?php

namespace App\Console\Commands;

use App\Services\Jobs\JobRecurrenceService;
use Illuminate\Console\Command;

class MaterializeJobRecurrencesCommand extends Command
{
    protected $signature = 'jobs:materialize-recurrences';

    protected $description = 'Create delivery jobs for active recurring series within each series horizon';

    public function handle(JobRecurrenceService $recurrences): int
    {
        $created = $recurrences->materializeAll();
        $this->info(sprintf('Materialized %d occurrence(s).', $created));

        return self::SUCCESS;
    }
}
