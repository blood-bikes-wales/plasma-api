<?php

namespace App\Enums;

/**
 * Whether a mileage reading is the shift start or end odometer.
 */
enum MileageReadingKind: string
{
    case Start = 'start';
    case End = 'end';
}
