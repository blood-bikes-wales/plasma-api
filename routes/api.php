<?php

use App\Enums\JobScope;
use App\Http\Controllers\Api\BikeController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\JobContentTypeController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PostcodeAreaController;
use App\Http\Controllers\Api\ServiceAreaController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\VolunteerController;
use Illuminate\Support\Facades\Route;

// API routes are prefixed with /api. Application endpoints will be added here.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
]));

Route::middleware(['auth.google', 'roles'])->group(function () {
    // Any signed-in user, whatever their roles: a profile and its own settings are not a capability.
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::patch('/me', [MeController::class, 'update'])->name('me.update');

    Route::middleware('access:view-active-shifts')->group(function () {
        Route::get('/shifts/active', [ShiftController::class, 'active'])->name('shifts.active');
    });

    Route::middleware('access:view-bikes')->group(function () {
        Route::get('/bikes', [BikeController::class, 'index'])->name('bikes.index');
    });

    Route::middleware('access:manage-bikes')->group(function () {
        Route::post('/bikes', [BikeController::class, 'store'])->name('bikes.store');
        Route::patch('/bikes/{bike}', [BikeController::class, 'update'])->name('bikes.update');
        Route::post('/bikes/{bike}/retire', [BikeController::class, 'retire'])->name('bikes.retire');
    });

    Route::middleware('access:view-volunteers')->group(function () {
        Route::get('/volunteers', [VolunteerController::class, 'index'])->name('volunteers.index');
    });

    Route::middleware('access:manage-shifts')->group(function () {
        Route::post('/shifts/logon', [ShiftController::class, 'logon'])->name('shifts.logon');
        Route::post('/shifts/{shift}/bike', [ShiftController::class, 'assignBike'])->name('shifts.assign-bike');
        Route::post('/shifts/{shift}/logoff', [ShiftController::class, 'logoff'])->name('shifts.logoff');
    });

    Route::middleware('access:view-jobs')->group(function () {
        Route::get('/jobs/{scope}', [JobController::class, 'index'])
            ->whereIn('scope', array_map(
                static fn (JobScope $scope): string => $scope->value,
                JobScope::cases(),
            ))
            ->name('jobs.index');
        // Constrained to a uuid so it can never swallow /jobs/active or /jobs/completed (or any
        // other word), and so a malformed id is a 404 rather than a Postgres uuid cast error.
        Route::get('/jobs/{job}', [JobController::class, 'show'])
            ->whereUuid('job')
            ->name('jobs.show');
    });

    Route::middleware('access:view-directory')->group(function () {
        Route::get('/directory/volunteers', [DirectoryController::class, 'volunteers'])
            ->name('directory.volunteers');
        Route::get('/directory/bikes', [DirectoryController::class, 'bikes'])
            ->name('directory.bikes');
        Route::get('/directory/bikes/{bike}', [DirectoryController::class, 'showBike'])
            ->name('directory.bikes.show');
    });

    Route::middleware('access:create-job')->group(function () {
        Route::post('/jobs', [JobController::class, 'store'])->name('jobs.store');
        Route::post('/jobs/{job}/relay', [JobController::class, 'relay'])->name('jobs.relay');
        Route::post('/jobs/{job}/actions/{action}', [JobController::class, 'action'])
            ->whereIn('action', ['allocate', 'collect', 'deliver'])
            ->name('jobs.action');
        Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel'])->name('jobs.cancel');
    });

    Route::middleware('access:view-postcode-areas')->group(function () {
        Route::get('/service-areas', [ServiceAreaController::class, 'index'])
            ->name('service-areas.index');
        Route::get('/postcode-areas', [PostcodeAreaController::class, 'index'])
            ->name('postcode-areas.index');
        Route::get('/postcode-areas/lookup', [PostcodeAreaController::class, 'lookup'])
            ->name('postcode-areas.lookup');
    });

    Route::middleware('access:manage-postcode-areas')->group(function () {
        Route::post('/postcode-areas', [PostcodeAreaController::class, 'store'])
            ->name('postcode-areas.store');
        Route::patch('/postcode-areas/{outbound_postcode_area}', [PostcodeAreaController::class, 'update'])
            ->name('postcode-areas.update');
        Route::post('/postcode-areas/import', [PostcodeAreaController::class, 'import'])
            ->name('postcode-areas.import');
    });

    Route::middleware('access:view-job-contents')->group(function () {
        Route::get('/job-contents', [JobContentTypeController::class, 'index'])
            ->name('job-contents.index');
    });

    Route::middleware('access:manage-job-contents')->group(function () {
        Route::post('/job-contents', [JobContentTypeController::class, 'store'])
            ->name('job-contents.store');
        Route::patch('/job-contents/{jobContent}', [JobContentTypeController::class, 'update'])
            ->name('job-contents.update');
    });
});
