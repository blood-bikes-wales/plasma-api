<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;

trait HasUuidPrimaryKey
{
    use HasVersion4Uuids;
}
