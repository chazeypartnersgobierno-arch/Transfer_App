<?php

use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\FaseController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

// Solo para quien NO ha iniciado sesión.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'mostrarFormulario'])->name('login');
    // throttle:login aplica el límite de intentos definido en AppServiceProvider.
    Route::post('/login', [LoginController::class, 'iniciarSesion'])->middleware('throttle:login');
});

// Solo para quien SÍ ha iniciado sesión; a los demás los envía a /login.
Route::middleware('auth')->group(function () {
    Route::view('/', 'inicio')->name('inicio');
    Route::post('/logout', [LoginController::class, 'cerrarSesion'])->name('logout');

    // Consultas: cualquier usuario con sesión.
    Route::get('/empresas', [EmpresaController::class, 'index'])->name('empresas.index');
    Route::get('/fases', [FaseController::class, 'index'])->name('fases.index');

    // Cambiar el nombre de una fase: quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar-fases')->group(function () {
        Route::get('/fases/{fase}/editar', [FaseController::class, 'editar'])->name('fases.editar');
        Route::put('/fases/{fase}', [FaseController::class, 'actualizar'])->name('fases.actualizar');
    });

    // Cambios en empresas: quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar-empresas')->group(function () {
        Route::get('/empresas/crear', [EmpresaController::class, 'crear'])->name('empresas.crear');
        Route::post('/empresas', [EmpresaController::class, 'guardar'])->name('empresas.guardar');
        Route::get('/empresas/{empresa}/editar', [EmpresaController::class, 'editar'])->name('empresas.editar');
        Route::put('/empresas/{empresa}', [EmpresaController::class, 'actualizar'])->name('empresas.actualizar');
        Route::patch('/empresas/{empresa}/baja', [EmpresaController::class, 'darDeBaja'])->name('empresas.baja');
        Route::patch('/empresas/{empresa}/reactivar', [EmpresaController::class, 'reactivar'])->name('empresas.reactivar');
    });
});
