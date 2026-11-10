<?php

namespace App\Http\Controllers;

use App\Models\Proceso;
use App\Models\Seguimiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Equipo del proceso: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS. Son los mismos para todos los seguimientos de un proceso, así que
 * se capturan una vez y se copian a todos sus seguimientos.
 * Se muestra en la lista de seguimientos al filtrar por proceso (SeguimientoController::index).
 * Admin y consultor (Gate dar-seguimiento); cada uno solo ve los procesos de su empresa.
 */
class EquipoDeProcesoController extends Controller
{
    public function actualizar(Request $request, Proceso $proceso): RedirectResponse
    {
        if (! $proceso->estado) {
            return back()->with('error', "El proceso {$proceso->nombre} está dado de baja: su equipo no se puede cambiar.");
        }

        $total = Seguimiento::delProceso($proceso->id_proceso)->count();

        if ($total === 0) {
            return back()->with('error', "El proceso {$proceso->nombre} todavía no tiene seguimientos.");
        }

        $reglas = array_fill_keys(array_keys(Seguimiento::CAMPOS_DEL_PROCESO), ['nullable', 'string', 'max:255']);
        $datos = $request->validate($reglas, ['*.max' => 'Este campo no puede tener más de 255 caracteres.']);

        // Una sola consulta para todos los seguimientos del proceso, también los de grupos o
        // actividades de baja: así, si se reactivan, ya tienen el equipo correcto.
        Seguimiento::delProceso($proceso->id_proceso)->update($datos);

        // De vuelta a la lista, con los filtros con los que se estaba trabajando.
        return back()->with('exito', "Se guardó el equipo del proceso {$proceso->nombre}. "
            .($total === 1 ? 'Se actualizó 1 seguimiento.' : "Se actualizaron {$total} seguimientos."));
    }
}
