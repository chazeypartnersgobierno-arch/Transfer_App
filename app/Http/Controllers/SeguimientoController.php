<?php

namespace App\Http\Controllers;

use App\Enums\EstadoActividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        return view('seguimientos.index', compact(
            'seguimientos', 'buscar', 'verBajas', 'idEmpresa', 'idBloque', 'idGrupo', 'idFase', 'estado',
            'empresas', 'bloques', 'grupos', 'fases', 'estados'
        ));
    }

    /**
     * Si el grupo o la actividad están de baja, la misma pantalla sirve solo para consultar.
     */
    public function editar(Seguimiento $seguimiento): View
    {
        $seguimiento->load(['actividad', 'grupo.bloque.empresa', 'fase']);

        return view('seguimientos.formulario', ['seguimiento' => $seguimiento]);
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

        $seguimiento->update($datos);

        return redirect()->to($this->urlDeRegreso($request))
            ->with('exito', "Se guardó el seguimiento de {$seguimiento->actividad->nombre} ({$seguimiento->grupo->nombre}, {$seguimiento->fase->nombre}).");
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
