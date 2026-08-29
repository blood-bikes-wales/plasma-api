<?php

namespace App\Enums;

enum JobStatus: string
{
    case New = 'New';
    case Allocated = 'Allocated';
    case Collected = 'Collected';
    case Delivered = 'Delivered';
    case Cancelled = 'Cancelled';
}
