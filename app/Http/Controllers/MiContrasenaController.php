<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cada usuario (admin o consultor) cambia su propia contraseña escribiendo la actual.
 */
class MiContrasenaController extends Controller
{
    public function editar(): View
    {
        return view('cuenta.contrasena');
    }

    public function actualizar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            // current_password compara contra el hash guardado del usuario con sesión.
            'contrasena_actual' => ['required', 'string', 'current_password'],
            'contrasena' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:contrasena_actual'],
        ], [
            'contrasena_actual.required' => 'Escribe tu contraseña actual.',
            'contrasena_actual.current_password' => 'La contraseña actual no es correcta.',
            'contrasena.required' => 'Escribe la nueva contraseña.',
            'contrasena.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'contrasena.max' => 'La nueva contraseña no puede tener más de 255 caracteres.',
            'contrasena.confirmed' => 'Las contraseñas nuevas no coinciden.',
            'contrasena.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        // El modelo la convierte en hash al guardar.
        $request->user()->update(['contrasena_hash' => $datos['contrasena']]);

        return redirect()->route('inicio')->with('exito', 'Se cambió tu contraseña.');
    }
}
