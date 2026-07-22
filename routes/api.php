<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// API routes are prefixed with /api. Application endpoints will be added here.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
]));

Route::middleware('auth.google')->group(function () {
    Route::get('/me', fn (Request $request) => response()->json($request->user()))->name('me');
});
