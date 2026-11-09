<?php

use App\Http\Middleware\UsuarioActivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nombre corto para usar en las rutas: ->middleware('activo').
        $middleware->alias(['activo' => UsuarioActivo::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
