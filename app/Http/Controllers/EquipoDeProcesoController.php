<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Proceso;
use App\Models\Seguimiento;
use App\Services\GeneradorDeSeguimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Equipo del proceso: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS. Son los mismos para todos los seguimientos de un proceso, así que
 * se capturan una vez y se copian a todos sus seguimientos.
 * Tiene su propia pantalla (menú Seguimientos › Equipos de proceso).
 * Admin y consultor (Gate dar-seguimiento); cada uno solo ve los procesos de su empresa
 * (el modelo Proceso da 404 si el proceso de la URL es de otra empresa).
 */
class EquipoDeProcesoController extends Controller
{
    /**
     * Todos los procesos con su equipo, para ver de un vistazo cuáles faltan por definir.
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = is_numeric($request->query('id_empresa')) ? (int) $request->query('id_empresa') : null;

        $procesos = Proceso::visiblePara($usuario)
            ->with('empresa') // todas las empresas de una vez, sin una consulta por fila
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            // addcslashes: para que % y _ se busquen como texto normal.
            ->when($buscar !== '', fn ($consulta) => $consulta->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra el filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();

        $equipos = $this->equiposDe($procesos->pluck('id_proceso')->all());

        return view('equipos.index', compact('procesos', 'equipos', 'buscar', 'verBajas', 'idEmpresa', 'empresas'));
    }

    /**
     * Si el proceso está de baja o todavía no tiene seguimientos, la misma pantalla sirve solo para consultar.
     */
    public function editar(Request $request, Proceso $proceso): View
    {
        $consulta = Seguimiento::delProceso($proceso->id_proceso);
        $total = (clone $consulta)->count();

        // Si los seguimientos tienen valores distintos (por ejemplo, de antes de esta regla),
        // se avisa: al guardar se igualan. distinct() cuenta también el vacío (NULL) como un valor.
        $distintos = collect(Seguimiento::CAMPOS_DEL_PROCESO)
            ->filter(fn ($etiqueta, $campo) => (clone $consulta)->select($campo)->distinct()->get()->count() > 1)
            ->values();

        return view('equipos.formulario', [
            'proceso' => $proceso->load('empresa'),
            'valores' => app(GeneradorDeSeguimientos::class)->equipoDelProceso($proceso->id_proceso),
            'total' => $total,
            'distintos' => $distintos,
            'editable' => $proceso->estado && $total > 0,
            'volverA' => $this->urlDeRegreso(old('volver_a', url()->previous())),
        ]);
    }

    public function actualizar(Request $request, Proceso $proceso): RedirectResponse
    {
        if (! $proceso->estado) {
            return redirect()->route('equipos.editar', $proceso)
                ->with('error', "El proceso {$proceso->nombre} está dado de baja: su equipo no se puede cambiar.");
        }

        $total = Seguimiento::delProceso($proceso->id_proceso)->count();

        if ($total === 0) {
            return redirect()->route('equipos.editar', $proceso)
                ->with('error', "El proceso {$proceso->nombre} todavía no tiene seguimientos.");
        }

        $reglas = array_fill_keys(array_keys(Seguimiento::CAMPOS_DEL_PROCESO), ['nullable', 'string', 'max:255']);
        $datos = $request->validate($reglas, ['*.max' => 'Este campo no puede tener más de 255 caracteres.']);

        // Una sola consulta para todos los seguimientos del proceso, también los de grupos o
        // actividades de baja: así, si se reactivan, ya tienen el equipo correcto.
        // No cambia el autor: el equipo no cuenta como captura de un seguimiento.
        Seguimiento::delProceso($proceso->id_proceso)->update($datos);

        return redirect()->to($this->urlDeRegreso($request->input('volver_a')))
            ->with('exito', "Se guardó el equipo del proceso {$proceso->nombre}. "
                .($total === 1 ? 'Se actualizó 1 seguimiento.' : "Se actualizaron {$total} seguimientos."));
    }

    /**
     * Equipo y número de seguimientos de cada proceso, en dos consultas para toda la página
     * (no una por proceso). El equipo se toma del seguimiento más reciente, igual que
     * GeneradorDeSeguimientos::equipoDelProceso.
     *
     * @return array<int, array{total: int, valores: array<string, ?string>}>
     */
    private function equiposDe(array $idsProcesos): array
    {
        // Por proceso: cuántos seguimientos tiene y cuál es el más reciente.
        $resumen = Seguimiento::query()
            ->join('grupos', 'grupos.id_grupo', '=', 'seguimientos.id_grupo')
            ->whereIn('grupos.id_proceso', $idsProcesos)
            ->groupBy('grupos.id_proceso')
            ->selectRaw('grupos.id_proceso, COUNT(*) AS total, MAX(seguimientos.id_seguimiento) AS ultimo')
            ->get();

        $campos = array_keys(Seguimiento::CAMPOS_DEL_PROCESO);
        $ultimos = Seguimiento::whereIn('id_seguimiento', $resumen->pluck('ultimo'))
            ->get(['id_seguimiento', ...$campos])
            ->keyBy('id_seguimiento');

        return $resumen->mapWithKeys(fn ($fila) => [(int) $fila->id_proceso => [
            'total' => (int) $fila->total,
            'valores' => $ultimos[$fila->ultimo]->only($campos),
        ]])->all();
    }

    /**
     * A dónde volver al guardar o cancelar: la lista de equipos o la de seguimientos (con sus
     * filtros), según de dónde se llegó. Nunca otra página u otro sitio.
     */
    private function urlDeRegreso(mixed $volverA): string
    {
        foreach ([route('equipos.index'), route('seguimientos.index')] as $lista) {
            if (is_string($volverA) && ($volverA === $lista || str_starts_with($volverA, $lista.'?'))) {
                return $volverA;
            }
        }

        return route('equipos.index');
    }
}
