<?php

namespace App\Http\Controllers;

use App\Enums\RolApp;
use App\Models\AsignacionEmpresa;
use App\Models\Empresa;
use App\Models\Usuario;
use App\Services\AsignadorDeEmpresas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Pantalla Asignaciones de un consultor (solo admin): historial y cambio de empresa.
 * Las reglas viven en App\Services\AsignadorDeEmpresas.
 */
class AsignacionEmpresaController extends Controller
{
    public function index(Usuario $usuario): View|RedirectResponse
    {
        if ($usuario->rol_app !== RolApp::Consultor) {
            return redirect()->route('usuarios.index')->with('error', 'El administrador ve todas las empresas: no lleva asignaciones.');
        }

        $asignaciones = $usuario->asignaciones()
            ->with('empresa')
            ->orderByDesc('activa')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id_usuario_empresa')
            ->get();

        // No se ofrece la empresa con la que ya trabaja.
        $empresas = Empresa::where('estado', true)
            ->whereNotIn('id_empresa', $asignaciones->where('activa', true)->pluck('id_empresa'))
            ->orderBy('nombre')
            ->pluck('nombre', 'id_empresa');

        return view('usuarios.asignaciones', compact('usuario', 'asignaciones', 'empresas'));
    }

    /**
     * Asigna otra empresa. Por la regla de una sola asignación activa, la anterior se cierra.
     */
    public function guardar(Request $request, Usuario $usuario, AsignadorDeEmpresas $asignador): RedirectResponse
    {
        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', 'exists:empresas,id_empresa'],
            'fecha_inicio' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe.',
            'fecha_inicio.required' => 'Escribe desde cuándo trabaja con la empresa.',
            'fecha_inicio.date' => 'Escribe una fecha válida.',
            'fecha_inicio.before_or_equal' => 'La fecha de inicio no puede ser futura.',
        ]);

        $anteriores = $usuario->asignacionesActivas()->with('empresa')->get()->pluck('empresa.nombre');
        $empresa = Empresa::findOrFail($datos['id_empresa']);

        $asignador->asignar($usuario, $empresa, Carbon::parse($datos['fecha_inicio']));

        $mensaje = "Se asignó {$usuario->nombre} a {$empresa->nombre}.";

        if ($anteriores->isNotEmpty()) {
            $mensaje .= ' Se cerró su asignación con '.$anteriores->implode(', ').'.';
        }

        return redirect()->route('usuarios.asignaciones', $usuario)->with('exito', $mensaje);
    }

    /**
     * Cierra la asignación sin abrir otra: el consultor queda sin empresa y no ve datos.
     */
    public function cerrar(AsignacionEmpresa $asignacion, AsignadorDeEmpresas $asignador): RedirectResponse
    {
        $asignador->cerrar($asignacion);

        return redirect()->route('usuarios.asignaciones', $asignacion->id_usuario)
            ->with('exito', "Se cerró la asignación con {$asignacion->empresa->nombre}.");
    }
}
