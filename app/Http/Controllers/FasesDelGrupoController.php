<?php

namespace App\Http\Controllers;

use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Services\AvanceDeFases;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fases de un grupo en pestañas, en su orden: completas con ✔, la actual resaltada y las
 * bloqueadas con candado. Admin y consultor (Gate dar-seguimiento).
 * Una fase bloqueada no carga sus actividades: muestra qué fase falta concluir y su avance.
 */
class FasesDelGrupoController extends Controller
{
    public function mostrar(Request $request, Grupo $grupo): View
    {
        $grupo->load('proceso.empresa');
        $avance = app(AvanceDeFases::class);
        $actual = $avance->faseActual($grupo);

        // Una pestaña por fase, con lo que necesita la vista.
        $pestanas = $avance->fases()->map(fn (Fase $fase) => (object) [
            'fase' => $fase,
            'completa' => $avance->faseCompleta($fase, $grupo),
            'habilitada' => $avance->faseHabilitada($fase, $grupo),
            'actual' => $actual?->is($fase) ?? false,
            'avance' => $avance->avance($fase, $grupo),
        ]);

        // La fase elegida en la URL (?fase=); si no hay, la actual; si ya completó todas, la última.
        $idFase = is_numeric($request->query('fase')) ? (int) $request->query('fase') : null;
        $elegida = $pestanas->first(fn ($p) => $p->fase->id_fase === $idFase)
            ?? $pestanas->first(fn ($p) => $p->actual)
            ?? $pestanas->last();

        $bloqueo = $avance->mensajeDeBloqueo($elegida->fase, $grupo);

        // Si la fase está bloqueada no se cargan sus actividades.
        $seguimientos = $bloqueo !== null
            ? collect()
            : Seguimiento::enTrabajo()
                ->where('id_grupo', $grupo->id_grupo)
                ->where('id_fase', $elegida->fase->id_fase)
                ->with(['actividad', 'fase', 'grupo'])
                ->orderBy('id_actividad')
                ->get();

        return view('grupos.fases', compact('grupo', 'pestanas', 'elegida', 'bloqueo', 'seguimientos'));
    }
}
