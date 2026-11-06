<?php

namespace App\Providers;

use App\Enums\RolApp;
use App\Models\Usuario;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Un Gate es una regla de permiso con nombre. El servidor la revisa en las
        // rutas con ->middleware('can:administrar'): quien no cumple recibe error 403.

        // Usuarios, cambios en catálogos y carga desde Excel: solo admin.
        Gate::define('administrar', fn (Usuario $usuario) => $usuario->rol_app === RolApp::Admin);

        // Seguimientos, sesiones, asistencia y avances: admin y consultor.
        Gate::define('dar-seguimiento', fn (Usuario $usuario) => in_array(
            $usuario->rol_app,
            [RolApp::Admin, RolApp::Consultor],
            true
        ));

        // Máximo 5 intentos de login por minuto para cada correo.
        RateLimiter::for('login', function (Request $request) {
            $correo = $request->input('correo');

            return Limit::perMinute(5)
                ->by(is_string($correo) ? mb_strtolower(trim($correo)) : '')
                ->response(function (Request $request, array $headers) {
                    $segundos = (int) $headers['Retry-After'];
                    $unidad = $segundos === 1 ? 'segundo' : 'segundos';

                    return redirect()->route('login')
                        ->withErrors(['acceso' => "Demasiados intentos. Espera {$segundos} {$unidad} e inténtalo de nuevo."])
                        ->withInput($request->only('correo'));
                });
        });
    }
}
