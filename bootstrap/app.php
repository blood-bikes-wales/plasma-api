<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AttachUserRoles;
use App\Http\Middleware\AuthenticateGoogleWorkspace;
use App\Http\Middleware\EnsureHasAnyRole;
use App\Http\Middleware\EnsureHasCapability;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Early so auth failures, Three Rings, and access logs share one ID.
        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'auth.google' => AuthenticateGoogleWorkspace::class,
            'roles' => AttachUserRoles::class,
            'role' => EnsureHasAnyRole::class,
            'access' => EnsureHasCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
