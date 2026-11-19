<?php

namespace App\Enums;

/**
 * Where a bike was left when its rider logged off a shift.
 */
enum BikeKeptAt: string
{
    case Lockup = 'lockup';
    case Home = 'home';
}
