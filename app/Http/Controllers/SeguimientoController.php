<?php

namespace App\Http\Controllers;

use App\Enums\EstadoActividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Services\AvanceDeFases;
use App\Services\GeneradorDeSeguimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lista de trabajo y edición de seguimientos. Admin y consultor (Gate dar-seguimiento).
 * Los seguimientos se generan solos (GeneradorDeSeguimientos): aquí no se crean ni se borran.
 */
class SeguimientoController extends Controller
{
    /** Valor del filtro de estado para ver los retrasados (no es un estado guardado). */
    public const RETRASADO = 'Retrasado';

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = $this->numeroDeLaUrl($request, 'id_empresa');
        $idFase = $this->numeroDeLaUrl($request, 'id_fase');
        $estado = is_string($request->query('estado')) ? $request->query('estado') : '';
        $estado = $estado === self::RETRASADO || EstadoActividad::tryFrom($estado) ? $estado : '';

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();
        $conSigla = $usuario->esChazey() && $idEmpresa === null;

        $bloques = Bloque::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Bloque $b) => [$b->id_bloque => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);

        // Un filtro que ya no está en su lista (por ejemplo, al cambiar de empresa) se ignora.
        $idBloque = $this->numeroDeLaUrl($request, 'id_bloque');
        $idBloque = $idBloque !== null && $bloques->has($idBloque) ? $idBloque : null;

        // Los grupos dependen del bloque elegido; sin bloque, los de las empresas visibles.
        $grupos = Grupo::visiblePara($usuario)
            ->with('bloque')
            ->when($idBloque !== null, fn ($consulta) => $consulta->where('id_bloque', $idBloque))
            ->when($idBloque === null && $idEmpresa !== null, fn ($consulta) => $consulta->whereHas('bloque', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->get()
            ->sortBy(fn (Grupo $g) => [$g->bloque->nombre, $g->nombre])
            ->mapWithKeys(fn (Grupo $g) => [$g->id_grupo => $idBloque !== null ? $g->nombre : "{$g->bloque->nombre} › {$g->nombre}"]);

        $idGrupo = $this->numeroDeLaUrl($request, 'id_grupo');
        $idGrupo = $idGrupo !== null && $grupos->has($idGrupo) ? $idGrupo : null;

        $fases = Fase::orderBy('orden')->pluck('nombre', 'id_fase');
        $idFase = $idFase !== null && $fases->has($idFase) ? $idFase : null;

        $seguimientos = Seguimiento::visiblePara($usuario)
            ->with(['actividad', 'grupo.bloque.empresa', 'fase']) // todo de una vez, sin una consulta por fila
            ->withCount('sesiones')
            ->with('avanceActual') // el % actual de los de Validación
            // Por defecto, solo los de grupos y actividades activos.
            ->when(! $verBajas, fn ($consulta) => $consulta->enTrabajo())
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('actividad', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($idBloque !== null, fn ($consulta) => $consulta->whereHas('grupo', fn ($q) => $q->where('id_bloque', $idBloque)))
            ->when($idGrupo !== null, fn ($consulta) => $consulta->where('id_grupo', $idGrupo))
            ->when($idFase !== null, fn ($consulta) => $consulta->where('id_fase', $idFase))
            ->when($estado === self::RETRASADO, fn ($consulta) => $consulta->retrasados())
            ->when($estado !== '' && $estado !== self::RETRASADO, fn ($consulta) => $consulta->conEstado(EstadoActividad::from($estado)))
            // addcslashes: para que % y _ se busquen como texto normal.
            ->when($buscar !== '', fn ($consulta) => $consulta->whereHas('actividad', fn ($q) => $q->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%')))
            // Por fase (según su orden), luego en el orden en que se cargaron las actividades, luego por grupo.
            ->orderBy(Fase::select('orden')->whereColumn('fases.id_fase', 'seguimientos.id_fase'))
            ->orderBy('id_actividad')
            ->orderBy('id_grupo')
            ->paginate(20)
            ->withQueryString();

        $estados = EstadoActividad::opciones() + [self::RETRASADO => self::RETRASADO];

        // Seguimientos de la página cuya fase todavía no se habilita para su grupo (una consulta por grupo).
        $avanceDeFases = app(AvanceDeFases::class);
        $bloqueados = $seguimientos->getCollection()
            ->filter(fn (Seguimiento $s) => ! $avanceDeFases->faseHabilitada($s->fase, $s->grupo))
            ->pluck('id_seguimiento')
            ->flip();

        // Con un bloque elegido, arriba de la tabla se muestra (y se cambia) su equipo.
        $equipo = $idBloque !== null ? $this->equipoDelBloque(Bloque::findOrFail($idBloque)) : null;

        return view('seguimientos.index', compact(
            'seguimientos', 'buscar', 'verBajas', 'idEmpresa', 'idBloque', 'idGrupo', 'idFase', 'estado',
            'empresas', 'bloques', 'grupos', 'fases', 'estados', 'bloqueados', 'equipo'
        ));
    }

    /**
     * Datos de la tarjeta "Equipo del bloque": el bloque, sus valores actuales, cuántos
     * seguimientos se actualizarán al guardar y en qué campos hay valores distintos.
     * Se guarda con EquipoDeBloqueController.
     */
    private function equipoDelBloque(Bloque $bloque): array
    {
        $consulta = Seguimiento::delBloque($bloque->id_bloque);

        // Si los seguimientos tienen valores distintos (por ejemplo, de antes de esta regla),
        // se avisa: al guardar se igualan. distinct() cuenta también el vacío (NULL) como un valor.
        $distintos = collect(Seguimiento::CAMPOS_DEL_BLOQUE)
            ->filter(fn ($etiqueta, $campo) => (clone $consulta)->select($campo)->distinct()->get()->count() > 1)
            ->values();

        $total = (clone $consulta)->count();

        return [
            'bloque' => $bloque,
            'valores' => app(GeneradorDeSeguimientos::class)->equipoDelBloque($bloque->id_bloque),
            'total' => $total,
            'distintos' => $distintos,
            'editable' => $bloque->estado && $total > 0,
        ];
    }

    /**
     * Si el grupo o la actividad están de baja, la misma pantalla sirve solo para consultar.
     */
    public function editar(Seguimiento $seguimiento): View
    {
        $seguimiento->load([
            'actividad',
            'grupo.bloque.empresa',
            'fase',
            'autor',
            // Cada sesión con su autor, cuántos registros de asistencia tiene y cuántos asistieron.
            'sesiones' => fn ($q) => $q
                ->with('autor')
                ->withCount(['asistencias', 'asistencias as asistentes_count' => fn ($a) => $a->where('asistio', true)])
                ->orderByDesc('fecha')
                ->orderByDesc('id_sesion'),
            // Avances de validación, el más reciente primero (ese es el % actual).
            'avances' => fn ($q) => $q->with('usuario')->orderByDesc('fecha_corte'),
        ]);

        return view('seguimientos.formulario', [
            'seguimiento' => $seguimiento,
            // Si su fase no está habilitada para el grupo, el mensaje con el candado (y solo se consulta).
            'bloqueo' => app(AvanceDeFases::class)->bloqueoDe($seguimiento),
        ]);
    }

    /**
     * Actividad, grupo, fase y el equipo del bloque quedan fijos. fecha_cierre la llena el modelo al pasar a
     * Completada (ver Seguimiento::booted), por eso no se recibe del formulario.
     */
    public function actualizar(Request $request, Seguimiento $seguimiento): RedirectResponse
    {
        if (! $seguimiento->estaEnTrabajo()) {
            return redirect()->route('seguimientos.editar', $seguimiento)
                ->with('error', 'Este seguimiento es de un grupo o una actividad dados de baja: solo se puede consultar.');
        }

        // La misma regla que en la pantalla: no se confía solo en la vista.
        if ($bloqueo = app(AvanceDeFases::class)->bloqueoDe($seguimiento)) {
            return redirect()->route('seguimientos.editar', $seguimiento)->with('error', $bloqueo);
        }

        $datos = $request->validate([
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado_actividad' => ['required', Rule::enum(EstadoActividad::class)],
            'documentacion' => ['nullable', 'string', 'max:500'],
            'comentarios' => ['nullable', 'string', 'max:10000'],
            // El experto es individual. Responsable, documentador y líderes son del bloque:
            // no se reciben aquí, se capturan en EquipoDeBloqueController.
            'experto' => ['nullable', 'string', 'max:255'],
        ], [
            'fecha_inicio.date' => 'Escribe una fecha de inicio válida.',
            'fecha_fin.date' => 'Escribe una fecha fin válida.',
            'fecha_fin.after_or_equal' => 'La fecha fin no puede ser anterior a la fecha de inicio.',
            'estado_actividad.required' => 'Elige un estado.',
            'estado_actividad.enum' => 'Elige un estado de la lista.',
            'documentacion.max' => 'La documentación no puede tener más de 500 caracteres.',
            'comentarios.max' => 'Los comentarios no pueden tener más de 10,000 caracteres.',
            '*.max' => 'Este campo no puede tener más de 255 caracteres.',
        ]);

        $this->validarQueLasCapturasSigan($seguimiento, $datos);

        $estabaCompleta = app(AvanceDeFases::class)->faseCompleta($seguimiento->fase, $seguimiento->grupo);

        // id_usuario queda con quien hizo la última captura o corrección.
        $seguimiento->update([...$datos, 'id_usuario' => $request->user()->id_usuario]);

        $respuesta = redirect()->to($this->urlDeRegreso($request))
            ->with('exito', "Se guardó el seguimiento de {$seguimiento->actividad->nombre} ({$seguimiento->grupo->nombre}, {$seguimiento->fase->nombre}).");

        // Se permite reabrir una fase completa, pero se avisa: las fases siguientes vuelven a bloquearse.
        // Un objeto nuevo, para calcular con el estado ya guardado.
        if ($estabaCompleta && ! app(AvanceDeFases::class)->faseCompleta($seguimiento->fase, $seguimiento->grupo)) {
            $respuesta->with('aviso', "{$seguimiento->fase->nombre} del grupo {$seguimiento->grupo->nombre} ya no está completa: "
                .'las fases siguientes quedan bloqueadas hasta que se vuelva a completar. Lo ya capturado en ellas no se pierde.');
        }

        return $respuesta;
    }

    /**
     * Las sesiones y los avances de validación deben quedar dentro de la fecha de inicio y la fecha fin.
     * Si el seguimiento ya tiene alguno, no se pueden quitar sus fechas ni cambiarlas dejándolos fuera.
     */
    private function validarQueLasCapturasSigan(Seguimiento $seguimiento, array $datos): void
    {
        $tieneSesiones = $seguimiento->tieneSesiones();
        $nombre = $tieneSesiones ? 'sesiones' : 'avances';
        $consulta = $tieneSesiones ? $seguimiento->sesiones() : $seguimiento->avances();
        $columna = $tieneSesiones ? 'fecha' : 'fecha_corte';
        $fechas = $consulta->orderBy($columna)->pluck($columna)->map(fn ($fecha) => Carbon::parse($fecha)->startOfDay());

        if ($fechas->isEmpty()) {
            return;
        }

        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            throw ValidationException::withMessages([
                'fecha_inicio' => "No se pueden quitar las fechas: el seguimiento ya tiene {$nombre}.",
            ]);
        }

        $inicio = Carbon::parse($datos['fecha_inicio'])->startOfDay();
        $fin = Carbon::parse($datos['fecha_fin'])->startOfDay();
        $fuera = $fechas->reject(fn ($fecha) => $fecha->betweenIncluded($inicio, $fin))
            ->map(fn ($fecha) => $fecha->format('d/m/Y'))
            ->unique();

        if ($fuera->isNotEmpty()) {
            throw ValidationException::withMessages([
                'fecha_inicio' => $tieneSesiones
                    ? 'Con estas fechas quedarían fuera sesiones ya registradas ('.$fuera->implode(', ').'). '
                        .'Elige fechas que las incluyan o corrige primero esas sesiones.'
                    : 'Con estas fechas quedarían fuera avances ya registrados (cortes al '.$fuera->implode(', ').'). '
                        .'Elige fechas que los incluyan o corrige primero esos avances.',
            ]);
        }
    }

    /**
     * Vuelve a la lista con los mismos filtros con los que se abrió el formulario.
     * Solo se acepta una dirección de la lista de seguimientos, nunca otra página u otro sitio.
     */
    private function urlDeRegreso(Request $request): string
    {
        $lista = route('seguimientos.index');
        $volverA = $request->input('volver_a');

        return is_string($volverA) && ($volverA === $lista || str_starts_with($volverA, $lista.'?'))
            ? $volverA
            : $lista;
    }

    /**
     * Lee un filtro numérico de la URL; cualquier otro valor se ignora.
     */
    private function numeroDeLaUrl(Request $request, string $nombre): ?int
    {
        return is_numeric($request->query($nombre)) ? (int) $request->query($nombre) : null;
    }
}
