<?php

namespace Database\Seeders;

use App\Models\OutboundPostcodeArea;
use App\Models\ServiceArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class OutboundPostcodeAreaSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/postcode-mappings.csv');
        if (! File::exists($path)) {
            $this->command?->warn("Missing seed file: {$path}");

            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->command?->warn("Unable to open seed file: {$path}");

            return;
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return;
            }

            $indexes = array_flip($header);

            while (($row = fgetcsv($handle)) !== false) {
                $outbound = $this->cell($row, $indexes, 'outboundPostcode');
                $areaCode = $this->cell($row, $indexes, 'areaCode');
                $areaName = $this->cell($row, $indexes, 'areaFullName');
                $sortOrder = $this->cell($row, $indexes, 'sortOrder');
                $active = $this->cell($row, $indexes, 'active');

                if ($outbound === null || $areaCode === null || $areaName === null || $sortOrder === null) {
                    continue;
                }

                $code = strtoupper($areaCode);

                $serviceArea = ServiceArea::query()->updateOrCreate(
                    ['code' => $code],
                    ['full_name' => $areaName],
                );

                OutboundPostcodeArea::query()->updateOrCreate(
                    ['outbound_postcode' => strtoupper($outbound)],
                    [
                        'service_area_id' => $serviceArea->id,
                        'sort_order' => (int) $sortOrder,
                        'active' => filter_var($active ?? 'true', FILTER_VALIDATE_BOOLEAN),
                    ],
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $indexes
     */
    private function cell(array $row, array $indexes, string $key): ?string
    {
        if (! array_key_exists($key, $indexes)) {
            return null;
        }

        $value = $row[$indexes[$key]] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
