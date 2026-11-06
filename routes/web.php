<?php

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
});
