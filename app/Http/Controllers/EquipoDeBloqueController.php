<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Seguimiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Equipo del bloque: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS. Son los mismos para todos los seguimientos de un bloque, así que
 * se capturan una vez y se copian a todos sus seguimientos.
 * Se muestra en la lista de seguimientos al filtrar por bloque (SeguimientoController::index).
 * Admin y consultor (Gate dar-seguimiento); cada uno solo ve los bloques de su empresa.
 */
class EquipoDeBloqueController extends Controller
{
    public function actualizar(Request $request, Bloque $bloque): RedirectResponse
    {
        if (! $bloque->estado) {
            return back()->with('error', "El proceso {$bloque->nombre} está dado de baja: su equipo no se puede cambiar.");
        }

        $total = Seguimiento::delBloque($bloque->id_bloque)->count();

        if ($total === 0) {
            return back()->with('error', "El proceso {$bloque->nombre} todavía no tiene seguimientos.");
        }

        $reglas = array_fill_keys(array_keys(Seguimiento::CAMPOS_DEL_BLOQUE), ['nullable', 'string', 'max:255']);
        $datos = $request->validate($reglas, ['*.max' => 'Este campo no puede tener más de 255 caracteres.']);

        // Una sola consulta para todos los seguimientos del bloque, también los de grupos o
        // actividades de baja: así, si se reactivan, ya tienen el equipo correcto.
        Seguimiento::delBloque($bloque->id_bloque)->update($datos);

        // De vuelta a la lista, con los filtros con los que se estaba trabajando.
        return back()->with('exito', "Se guardó el equipo del proceso {$bloque->nombre}. "
            .($total === 1 ? 'Se actualizó 1 seguimiento.' : "Se actualizaron {$total} seguimientos."));
    }
}
