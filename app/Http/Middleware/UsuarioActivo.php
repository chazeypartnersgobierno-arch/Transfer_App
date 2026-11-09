<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca de la app a quien fue dado de baja mientras tenía la sesión abierta.
 * Laravel vuelve a leer al usuario de la base en cada petición, así que basta con
 * revisar su estado: en su siguiente clic se cierra la sesión y va al login.
 * (Las sesiones se guardan en archivos, así que no se pueden borrar las de un usuario).
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->estado) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['acceso' => 'Tu usuario está dado de baja. Contacta al administrador.']);
        }

        return $next($request);
    }
}
