<?php

use App\Enums\JobScope;
use App\Http\Controllers\Api\BikeController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\VolunteerController;
use Illuminate\Support\Facades\Route;

// API routes are prefixed with /api. Application endpoints will be added here.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
]));

Route::middleware(['auth.google', 'roles'])->group(function () {
    Route::get('/me', MeController::class)->name('me');

    Route::middleware('access:view-active-shifts')->group(function () {
        Route::get('/shifts/active', [ShiftController::class, 'active'])->name('shifts.active');
    });

    Route::middleware('access:view-bikes')->group(function () {
        Route::get('/bikes', [BikeController::class, 'index'])->name('bikes.index');
    });

    Route::middleware('access:view-volunteers')->group(function () {
        Route::get('/volunteers', [VolunteerController::class, 'index'])->name('volunteers.index');
    });

    Route::middleware('access:manage-shifts')->group(function () {
        Route::post('/shifts/logon', [ShiftController::class, 'logon'])->name('shifts.logon');
        Route::post('/shifts/{shift}/logoff', [ShiftController::class, 'logoff'])->name('shifts.logoff');
    });

    Route::middleware('access:view-jobs')->group(function () {
        Route::get('/jobs/{scope}', [JobController::class, 'index'])
            ->whereIn('scope', array_map(
                static fn (JobScope $scope): string => $scope->value,
                JobScope::cases(),
            ))
            ->name('jobs.index');
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
});
