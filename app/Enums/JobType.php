<?php

namespace App\Enums;

/**
 * What kind of job a controller is dispatching. Standard is the default courier job;
 * Milk is a breast milk run, which also records how many litres are being moved.
 */
enum JobType: string
{
    case Standard = 'standard';
    case Milk = 'milk';

    /**
     * Upper limit for milk_litres on a single job. To confirm with controllers.
     */
    public const MAX_MILK_LITRES = 100;
}
