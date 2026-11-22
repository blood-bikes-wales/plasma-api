<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'phone',
    'phone_secondary',
    'organisation',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, HasUuidPrimaryKey;
}
