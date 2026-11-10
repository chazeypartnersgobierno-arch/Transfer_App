<?php

namespace App\Http\Controllers;

use App\Enums\EstadoAcceso;
use App\Models\AccesoSolicitado;
use App\Models\Aplicativo;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accesos solicitados: admin y consultor (Gate administrar-accesos), cada uno en las
 * empresas que ve. No se borran: se corrigen editándolos.
 *
 * - Una solicitud se registra para uno o varios participantes a la vez (una por persona).
 * - No hay dos solicitudes en curso del mismo participante para el mismo aplicativo:
 *   solo se puede volver a pedir si la anterior está Rechazada.
 * - fecha_otorgado es obligatoria en Otorgado y se vacía al salir de Otorgado.
 * - Los cambios de estado son libres.
 */
class AccesoSolicitadoController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $idEmpresa = $this->numero($request, 'id_empresa');
        $estado = is_string($request->query('estado')) ? EstadoAcceso::tryFrom($request->query('estado')) : null;

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();
        $conSigla = $usuario->esChazey() && $idEmpresa === null;

        $aplicativos = Aplicativo::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Aplicativo $a) => [$a->id_aplicativo => $conSigla ? "{$a->nombre} ({$a->empresa->sigla})" : $a->nombre]);
        $idAplicativo = $this->elegido($this->numero($request, 'id_aplicativo'), $aplicativos);

        $grupos = $this->nombresDeGrupos(Grupo::visiblePara($usuario)
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('proceso', fn ($q) => $q->where('id_empresa', $idEmpresa))), $conSigla);
        $idGrupo = $this->elegido($this->numero($request, 'id_grupo'), $grupos);

        // Desde la lista de participantes se llega con la persona ya elegida.
        $idParticipante = $this->numero($request, 'id_participante');
        $participante = $idParticipante !== null ? Participante::visiblePara($usuario)->find($idParticipante) : null;

        $filtrados = AccesoSolicitado::visiblePara($usuario)
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('participante.grupo.proceso', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($idAplicativo !== null, fn ($consulta) => $consulta->where('id_aplicativo', $idAplicativo))
            ->when($idGrupo !== null, fn ($consulta) => $consulta->whereHas('participante', fn ($q) => $q->where('id_grupo', $idGrupo)))
            ->when($participante !== null, fn ($consulta) => $consulta->where('id_participante', $participante->id_participante))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';
                $consulta->whereHas('participante', fn ($q) => $q->where(fn ($p) => $p->where('nombre', 'like', $texto)
                    ->orWhere('apellidos', 'like', $texto)
                    ->orWhere('correo', 'like', $texto)));
            });

        // Cuántas hay en cada estado con los demás filtros, para ver lo pendiente de un vistazo.
        $totales = (clone $filtrados)->toBase()
            ->selectRaw('estado, COUNT(*) AS total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $accesos = (clone $filtrados)
            ->when($estado !== null, fn ($consulta) => $consulta->where('estado', $estado->value))
            ->with(['participante.grupo.proceso.empresa', 'aplicativo'])
            ->orderByDesc('fecha_solicitud')
            ->orderByDesc('id_acceso_solicitado')
            ->paginate(15)
            ->withQueryString();

        return view('accesos.index', compact(
            'accesos', 'totales', 'buscar', 'idEmpresa', 'idAplicativo', 'idGrupo', 'estado', 'participante', 'empresas', 'aplicativos', 'grupos'
        ));
    }

    /**
     * Pantalla para registrar: primero la empresa (si el usuario ve varias); después el
     * aplicativo y los participantes, que se pueden filtrar por proceso y grupo.
     */
    public function crear(Request $request): View|RedirectResponse
    {
        $usuario = $request->user();
        $empresas = $this->empresasActivas($usuario);

        if ($empresas->isEmpty()) {
            return redirect()->route('accesos.index')->with('error', 'No hay empresas activas para registrar accesos.');
        }

        $idEmpresa = $empresas->count() === 1 ? $empresas->keys()->first() : $this->elegido($this->numero($request, 'id_empresa'), $empresas);

        if ($idEmpresa === null) {
            return view('accesos.crear', ['empresas' => $empresas, 'idEmpresa' => null]);
        }

        $procesos = Proceso::where('id_empresa', $idEmpresa)->where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_proceso');
        $idProceso = $this->elegido($this->numero($request, 'id_proceso'), $procesos);

        $grupos = $this->nombresDeGrupos(Grupo::where('estado', true)
            ->whereHas('proceso', fn ($q) => $q->where('id_empresa', $idEmpresa)->where('estado', true))
            ->when($idProceso !== null, fn ($consulta) => $consulta->where('id_proceso', $idProceso)), false);
        $idGrupo = $this->elegido($this->numero($request, 'id_grupo'), $grupos);

        return view('accesos.crear', [
            'empresas' => $empresas,
            'idEmpresa' => $idEmpresa,
            'aplicativos' => Aplicativo::where('id_empresa', $idEmpresa)->where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_aplicativo'),
            'procesos' => $procesos,
            'idProceso' => $idProceso,
            'grupos' => $grupos,
            'idGrupo' => $idGrupo,
            'participantes' => $this->participantesParaElegir($idEmpresa, $idProceso, $idGrupo),
        ]);
    }

    /**
     * Crea una solicitud por participante elegido, todo en una transacción. Omite a quien ya
     * tiene una solicitud en curso de ese aplicativo y lo dice en el resumen.
     */
    public function guardar(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $empresas = $this->empresasActivas($usuario);
        $idEmpresa = $this->elegido(is_numeric($request->input('id_empresa')) ? (int) $request->input('id_empresa') : null, $empresas) ?? 0;

        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::in($empresas->keys()->all())],
            // El aplicativo debe ser activo y de la misma empresa que los participantes.
            'id_aplicativo' => ['required', 'integer', Rule::exists('aplicativos', 'id_aplicativo')->where('id_empresa', $idEmpresa)->where('estado', true)],
            'fecha_solicitud' => ['required', 'date', 'before_or_equal:today'],
            'comentarios' => ['nullable', 'string', 'max:10000'],
            'participantes' => ['required', 'array'],
            'participantes.*' => ['integer', Rule::in($this->participantesParaElegir($idEmpresa, null, null)->modelKeys())],
        ], [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.in' => 'Elige una empresa activa de la lista.',
            'id_aplicativo.required' => 'Elige un aplicativo.',
            'id_aplicativo.integer' => 'Elige un aplicativo de la lista.',
            'id_aplicativo.exists' => 'Elige un aplicativo activo de la empresa.',
            'participantes.required' => 'Elige al menos un participante.',
            'participantes.array' => 'Elige participantes de la lista.',
            'participantes.*.integer' => 'Elige participantes de la lista.',
            'participantes.*.in' => 'Elige participantes activos de la empresa.',
            ...$this->mensajesDeFechas(),
        ]);

        $aplicativo = Aplicativo::findOrFail($datos['id_aplicativo']);

        [$registrados, $omitidos] = DB::transaction(function () use ($datos, $aplicativo) {
            // Bloquea a los participantes elegidos: si dos personas registran a la vez,
            // la segunda espera y ve la solicitud de la primera, así no quedan duplicadas.
            $participantes = Participante::whereIn('id_participante', $datos['participantes'])
                ->orderBy('apellidos')
                ->orderBy('nombre')
                ->lockForUpdate()
                ->get();

            $conSolicitud = AccesoSolicitado::enCurso()
                ->where('id_aplicativo', $aplicativo->id_aplicativo)
                ->whereIn('id_participante', $participantes->modelKeys())
                ->pluck('id_participante')
                ->all();

            $registrados = [];
            $omitidos = [];

            foreach ($participantes as $participante) {
                if (in_array($participante->id_participante, $conSolicitud, true)) {
                    $omitidos[] = $participante->nombreCompleto();

                    continue;
                }

                AccesoSolicitado::create([
                    'id_participante' => $participante->id_participante,
                    'id_aplicativo' => $aplicativo->id_aplicativo,
                    'fecha_solicitud' => $datos['fecha_solicitud'],
                    'estado' => EstadoAcceso::Solicitado,
                    'comentarios' => filled($datos['comentarios'] ?? null) ? trim($datos['comentarios']) : null,
                ]);
                $registrados[] = $participante->nombreCompleto();
            }

            return [$registrados, $omitidos];
        });

        $respuesta = redirect()->route('accesos.index', ['id_aplicativo' => $aplicativo->id_aplicativo]);

        if ($registrados !== []) {
            $total = count($registrados);
            $respuesta->with('exito', ($total === 1 ? 'Se registró 1 solicitud' : "Se registraron {$total} solicitudes")
                ." de {$aplicativo->nombre}: ".Arr::join($registrados, ', ', ' y ').'.');
        }

        if ($omitidos !== []) {
            $total = count($omitidos);
            $respuesta->with('aviso', ($total === 1 ? 'Se omitió 1 porque ya tiene' : "Se omitieron {$total} porque ya tienen")
                ." una solicitud en curso de {$aplicativo->nombre}: ".Arr::join($omitidos, ', ', ' y ').'.');
        }

        return $respuesta;
    }

    public function editar(AccesoSolicitado $acceso): View
    {
        $acceso->load(['participante.grupo.proceso.empresa', 'aplicativo']);

        return view('accesos.editar', ['acceso' => $acceso, 'editable' => $acceso->sePuedeEditar()]);
    }

    /**
     * Cambia estado, fechas y comentarios. El participante y el aplicativo quedan fijos.
     */
    public function actualizar(Request $request, AccesoSolicitado $acceso): RedirectResponse
    {
        if (! $acceso->sePuedeEditar()) {
            return redirect()->route('accesos.editar', $acceso)
                ->with('error', 'El participante o el aplicativo están dados de baja: esta solicitud solo se puede consultar.');
        }

        $datos = $request->validate([
            'estado' => ['required', Rule::enum(EstadoAcceso::class)],
            'fecha_solicitud' => ['required', 'date', 'before_or_equal:today'],
            // Solo cuenta en Otorgado; en los demás estados se ignora y se guarda vacía.
            'fecha_otorgado' => ['exclude_unless:estado,'.EstadoAcceso::Otorgado->value, 'required', 'date', 'after_or_equal:fecha_solicitud', 'before_or_equal:today'],
            'comentarios' => ['nullable', 'string', 'max:10000'],
        ], [
            'estado.required' => 'Elige un estado.',
            'estado.enum' => 'Elige un estado de la lista.',
            ...$this->mensajesDeFechas(),
        ]);

        $estado = EstadoAcceso::from($datos['estado']);

        DB::transaction(function () use ($acceso, $datos, $estado) {
            // Bloquea al participante para que nadie registre a la vez otra solicitud en curso.
            Participante::whereKey($acceso->id_participante)->lockForUpdate()->first();

            $otraEnCurso = $estado->estaEnCurso() && AccesoSolicitado::enCurso()
                ->where('id_participante', $acceso->id_participante)
                ->where('id_aplicativo', $acceso->id_aplicativo)
                ->whereKeyNot($acceso->id_acceso_solicitado)
                ->exists();

            if ($otraEnCurso) {
                throw ValidationException::withMessages([
                    'estado' => "{$acceso->participante->nombreCompleto()} ya tiene otra solicitud en curso de {$acceso->aplicativo->nombre}.",
                ]);
            }

            $acceso->update([
                'estado' => $estado,
                'fecha_solicitud' => $datos['fecha_solicitud'],
                'fecha_otorgado' => $estado === EstadoAcceso::Otorgado ? $datos['fecha_otorgado'] : null,
                'comentarios' => filled($datos['comentarios'] ?? null) ? trim($datos['comentarios']) : null,
            ]);
        });

        return redirect()->route('accesos.index', ['id_participante' => $acceso->id_participante])
            ->with('exito', "Se actualizó la solicitud de {$acceso->aplicativo->nombre} de {$acceso->participante->nombreCompleto()}.");
    }

    /**
     * Participantes que se pueden elegir: activos, de grupos y procesos activos de la empresa.
     */
    private function participantesParaElegir(int $idEmpresa, ?int $idProceso, ?int $idGrupo): \Illuminate\Database\Eloquent\Collection
    {
        return Participante::where('estado', true)
            ->whereHas('grupo', fn ($g) => $g->where('estado', true)
                ->when($idProceso !== null, fn ($q) => $q->where('id_proceso', $idProceso))
                ->whereHas('proceso', fn ($b) => $b->where('id_empresa', $idEmpresa)->where('estado', true)))
            ->when($idGrupo !== null, fn ($consulta) => $consulta->where('id_grupo', $idGrupo))
            ->with('grupo.proceso')
            ->get()
            ->sortBy(fn (Participante $p) => [$p->grupo->proceso->nombre, $p->grupo->nombre, $p->apellidos, $p->nombre])
            ->values();
    }

    /**
     * Empresas activas que ve el usuario: id_empresa => nombre.
     */
    private function empresasActivas(Usuario $usuario): Collection
    {
        return Empresa::visiblePara($usuario)->where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa');
    }

    /**
     * Lista "Proceso › Grupo" (con la sigla de la empresa si hace falta distinguirlas).
     */
    private function nombresDeGrupos($consulta, bool $conSigla): Collection
    {
        return $consulta->with('proceso.empresa')
            ->get()
            ->sortBy(fn (Grupo $g) => [$g->proceso->nombre, $g->nombre])
            ->mapWithKeys(fn (Grupo $g) => [
                $g->id_grupo => "{$g->proceso->nombre} › {$g->nombre}".($conSigla ? " ({$g->proceso->empresa->sigla})" : ''),
            ]);
    }

    private function mensajesDeFechas(): array
    {
        return [
            'fecha_solicitud.required' => 'Escribe la fecha de solicitud.',
            'fecha_solicitud.date' => 'Escribe una fecha válida.',
            'fecha_solicitud.before_or_equal' => 'La fecha de solicitud no puede ser futura.',
            'fecha_otorgado.required' => 'Escribe la fecha en que se otorgó.',
            'fecha_otorgado.date' => 'Escribe una fecha válida.',
            'fecha_otorgado.after_or_equal' => 'La fecha otorgado no puede ser anterior a la fecha de solicitud.',
            'fecha_otorgado.before_or_equal' => 'La fecha otorgado no puede ser futura.',
            'comentarios.max' => 'Los comentarios no pueden tener más de 10,000 caracteres.',
        ];
    }

    /**
     * El número elegido solo si está en la lista (si no, se ignora).
     */
    private function elegido(?int $id, Collection $lista): ?int
    {
        return $id !== null && $lista->has($id) ? $id : null;
    }

    private function numero(Request $request, string $campo): ?int
    {
        return is_numeric($request->query($campo)) ? (int) $request->query($campo) : null;
    }
}
