<?php

use App\Http\Controllers\ActividadController;
use App\Http\Controllers\AplicativoController;
use App\Http\Controllers\BloqueController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\FaseController;
use App\Http\Controllers\GrupoController;
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

    // Consultas de catálogos por empresa: cada usuario solo ve lo que le corresponde.
    Route::get('/bloques', [BloqueController::class, 'index'])->name('bloques.index');
    Route::get('/grupos', [GrupoController::class, 'index'])->name('grupos.index');
    Route::get('/aplicativos', [AplicativoController::class, 'index'])->name('aplicativos.index');
    Route::get('/actividades', [ActividadController::class, 'index'])->name('actividades.index');

    // Cambios en catálogos por empresa: solo admin. Quien no cumple el Gate recibe error 403.
    Route::middleware('can:administrar')->group(function () {
        Route::get('/bloques/crear', [BloqueController::class, 'crear'])->name('bloques.crear');
        Route::post('/bloques', [BloqueController::class, 'guardar'])->name('bloques.guardar');
        Route::get('/bloques/{bloque}/editar', [BloqueController::class, 'editar'])->name('bloques.editar');
        Route::put('/bloques/{bloque}', [BloqueController::class, 'actualizar'])->name('bloques.actualizar');
        Route::patch('/bloques/{bloque}/baja', [BloqueController::class, 'darDeBaja'])->name('bloques.baja');
        Route::patch('/bloques/{bloque}/reactivar', [BloqueController::class, 'reactivar'])->name('bloques.reactivar');

        Route::get('/grupos/crear', [GrupoController::class, 'crear'])->name('grupos.crear');
        Route::post('/grupos', [GrupoController::class, 'guardar'])->name('grupos.guardar');
        Route::get('/grupos/{grupo}/editar', [GrupoController::class, 'editar'])->name('grupos.editar');
        Route::put('/grupos/{grupo}', [GrupoController::class, 'actualizar'])->name('grupos.actualizar');
        Route::patch('/grupos/{grupo}/baja', [GrupoController::class, 'darDeBaja'])->name('grupos.baja');
        Route::patch('/grupos/{grupo}/reactivar', [GrupoController::class, 'reactivar'])->name('grupos.reactivar');

        Route::get('/actividades/crear', [ActividadController::class, 'crear'])->name('actividades.crear');
        Route::post('/actividades', [ActividadController::class, 'guardar'])->name('actividades.guardar');
        Route::get('/actividades/{actividad}/editar', [ActividadController::class, 'editar'])->name('actividades.editar');
        Route::put('/actividades/{actividad}', [ActividadController::class, 'actualizar'])->name('actividades.actualizar');
        Route::patch('/actividades/{actividad}/baja', [ActividadController::class, 'darDeBaja'])->name('actividades.baja');
        Route::patch('/actividades/{actividad}/reactivar', [ActividadController::class, 'reactivar'])->name('actividades.reactivar');

        Route::get('/aplicativos/crear', [AplicativoController::class, 'crear'])->name('aplicativos.crear');
        Route::post('/aplicativos', [AplicativoController::class, 'guardar'])->name('aplicativos.guardar');
        Route::get('/aplicativos/{aplicativo}/editar', [AplicativoController::class, 'editar'])->name('aplicativos.editar');
        Route::put('/aplicativos/{aplicativo}', [AplicativoController::class, 'actualizar'])->name('aplicativos.actualizar');
        Route::patch('/aplicativos/{aplicativo}/baja', [AplicativoController::class, 'darDeBaja'])->name('aplicativos.baja');
        Route::patch('/aplicativos/{aplicativo}/reactivar', [AplicativoController::class, 'reactivar'])->name('aplicativos.reactivar');
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
