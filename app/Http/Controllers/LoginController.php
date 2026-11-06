<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function mostrarFormulario(): View
    {
        return view('auth.login');
    }

    public function iniciarSesion(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'correo' => ['required', 'email'],
            'contrasena' => ['required', 'string'],
        ], [
            'correo.required' => 'Escribe tu correo.',
            'correo.email' => 'Escribe un correo válido.',
            'contrasena.required' => 'Escribe tu contraseña.',
        ]);

        // Auth::attempt busca al usuario con estos datos y compara la contraseña
        // contra el hash. La clave debe llamarse 'password' aunque la columna sea
        // contrasena_hash. Con 'estado' => 1, los usuarios dados de baja no entran.
        $credenciales = [
            'correo' => $datos['correo'],
            'password' => $datos['contrasena'],
            'estado' => 1,
        ];

        if (! Auth::attempt($credenciales)) {
            // Auth::validate solo compara correo y contraseña, sin iniciar sesión. El aviso
            // de baja se da únicamente a quien conoce la contraseña; a los demás, el mismo
            // mensaje de siempre, para no revelar qué correos existen.
            $contrasenaCorrecta = Auth::validate([
                'correo' => $datos['correo'],
                'password' => $datos['contrasena'],
            ]);

            $mensaje = $contrasenaCorrecta
                ? 'Tu usuario está dado de baja. Contacta al administrador.'
                : 'El correo o la contraseña no son correctos.';

            return back()
                ->withErrors(['acceso' => $mensaje])
                ->onlyInput('correo');
        }

        // Cambia el identificador de la sesión para evitar que alguien reutilice uno anterior.
        $request->session()->regenerate();

        return redirect()->intended(route('inicio'));
    }

    public function cerrarSesion(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
