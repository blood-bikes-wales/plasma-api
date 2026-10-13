<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceAreaResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{code: string, name: string} $row */
        $row = $this->resource;

        return [
            'code' => $row['code'],
            'name' => $row['name'],
        ];
    }
}
