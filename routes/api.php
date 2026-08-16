<?php

use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

// API routes are prefixed with /api. Application endpoints will be added here.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
]));

Route::middleware(['auth.google', 'roles'])->group(function () {
    Route::get('/me', MeController::class)->name('me');
});
