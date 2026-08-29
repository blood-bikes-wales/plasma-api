<?php

use App\Http\Controllers\Api\BikeController;
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
    Route::get('/shifts/active', [ShiftController::class, 'active'])->name('shifts.active');
    Route::get('/bikes', [BikeController::class, 'index'])->name('bikes.index');
    Route::get('/volunteers', [VolunteerController::class, 'index'])->name('volunteers.index');

    Route::middleware('role:admin,controller')->group(function () {
        Route::post('/shifts/logon', [ShiftController::class, 'logon'])->name('shifts.logon');
        Route::post('/shifts/{shift}/logoff', [ShiftController::class, 'logoff'])->name('shifts.logoff');
    });
});
