<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Seguimiento;
use App\Services\GeneradorDeSeguimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Equipo del bloque: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS. Son los mismos para todos los seguimientos de un bloque, así que
 * se capturan aquí una vez y se copian a todos sus seguimientos.
 * Admin y consultor (Gate dar-seguimiento); cada uno solo ve los bloques de su empresa.
 */
class EquipoDeBloqueController extends Controller
{
    public function editar(Bloque $bloque, GeneradorDeSeguimientos $generador): View
    {
        $consulta = Seguimiento::delBloque($bloque->id_bloque);

        // Si los seguimientos tienen valores distintos (por ejemplo, de antes de esta regla),
        // se avisa: al guardar se igualan.
        $distintos = collect(Seguimiento::CAMPOS_DEL_BLOQUE)
            // distinct() cuenta también el vacío (NULL) como un valor.
            ->filter(fn ($etiqueta, $campo) => (clone $consulta)->select($campo)->distinct()->get()->count() > 1)
            ->values();

        return view('seguimientos.equipo', [
            'bloque' => $bloque->load('empresa'),
            'equipo' => $generador->equipoDelBloque($bloque->id_bloque),
            'totalSeguimientos' => (clone $consulta)->count(),
            'distintos' => $distintos,
        ]);
    }

    public function actualizar(Request $request, Bloque $bloque): RedirectResponse
    {
        if (! $bloque->estado) {
            return back()->with('error', "El bloque {$bloque->nombre} está dado de baja: su equipo no se puede cambiar.");
        }

        $total = Seguimiento::delBloque($bloque->id_bloque)->count();

        if ($total === 0) {
            return back()->with('error', "El bloque {$bloque->nombre} todavía no tiene seguimientos.");
        }

        $reglas = array_fill_keys(array_keys(Seguimiento::CAMPOS_DEL_BLOQUE), ['nullable', 'string', 'max:255']);
        $datos = $request->validate($reglas, ['*.max' => 'Este campo no puede tener más de 255 caracteres.']);

        // Una sola consulta para todos los seguimientos del bloque, también los de grupos o
        // actividades de baja: así, si se reactivan, ya tienen el equipo correcto.
        Seguimiento::delBloque($bloque->id_bloque)->update($datos);

        return redirect()->route('seguimientos.equipo', $bloque)
            ->with('exito', "Se guardó el equipo del bloque {$bloque->nombre}. "
                .($total === 1 ? 'Se actualizó 1 seguimiento.' : "Se actualizaron {$total} seguimientos."));
    }
}
