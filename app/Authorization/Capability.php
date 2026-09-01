<?php

namespace App\Authorization;

/**
 * Named permissions from the Plasma capability matrix.
 *
 * Routes check these, never a client-supplied role header.
 */
enum Capability: string
{
    case ViewActiveShifts = 'view-active-shifts';
    case ManageShifts = 'manage-shifts';
    case ViewBikes = 'view-bikes';
    case ViewVolunteers = 'view-volunteers';
    case ViewDirectory = 'view-directory';
    case CreateJob = 'create-job';
    case ViewJobs = 'view-jobs';
    case ManageBikes = 'manage-bikes';
}
