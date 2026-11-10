<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Usuario;
use App\Support\Texto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Participantes. Los consulta cualquier usuario; los administran el admin y el consultor
 * (Gate administrar-participantes). No se borran: se dan de baja con estado = 0.
 * Cada usuario solo ve los de su empresa (Participante::visiblePara).
 */
class ParticipanteController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = $this->numero($request, 'id_empresa');

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();
        $conSigla = $usuario->esChazey() && $idEmpresa === null;

        $bloques = Bloque::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Bloque $b) => [$b->id_bloque => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);

        // Un bloque o grupo que ya no está en su lista (por ejemplo, al cambiar de empresa) se ignora.
        $idBloque = $this->numero($request, 'id_bloque');
        $idBloque = $idBloque !== null && $bloques->has($idBloque) ? $idBloque : null;

        $grupos = Grupo::visiblePara($usuario)
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('bloque', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($idBloque !== null, fn ($consulta) => $consulta->where('id_bloque', $idBloque))
            ->orderBy('nombre')
            ->pluck('nombre', 'id_grupo');

        $idGrupo = $this->numero($request, 'id_grupo');
        $idGrupo = $idGrupo !== null && $grupos->has($idGrupo) ? $idGrupo : null;

        $participantes = Participante::visiblePara($usuario)
            ->with('grupo.bloque.empresa') // trae grupo, bloque y empresa de una vez
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('grupo.bloque', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($idBloque !== null, fn ($consulta) => $consulta->whereHas('grupo', fn ($q) => $q->where('id_bloque', $idBloque)))
            ->when($idGrupo !== null, fn ($consulta) => $consulta->where('id_grupo', $idGrupo))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';
                $consulta->where(fn ($q) => $q->where('nombre', 'like', $texto)
                    ->orWhere('apellidos', 'like', $texto)
                    ->orWhere('correo', 'like', $texto));
            })
            ->orderBy('apellidos')
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('participantes.index', compact(
            'participantes', 'buscar', 'verBajas', 'idEmpresa', 'idBloque', 'idGrupo', 'empresas', 'bloques', 'grupos'
        ));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $grupos = $this->gruposParaElegir($request->user());

        if ($grupos->isEmpty()) {
            return redirect()->route('participantes.index')->with('error', 'Primero registra un grupo activo.');
        }

        return view('participantes.formulario', ['participante' => new Participante, 'grupos' => $grupos]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $grupos = $this->gruposParaElegir($request->user());
        $datos = $this->validar($request, $grupos);

        $participante = Participante::create($datos);

        return redirect()->route('participantes.index')->with('exito', "Se agregó a {$participante->nombreCompleto()}.");
    }

    public function editar(Request $request, Participante $participante): View
    {
        return view('participantes.formulario', [
            'participante' => $participante,
            'grupos' => $this->gruposParaElegir($request->user(), $participante),
        ]);
    }

    /**
     * Puede cambiar de grupo, pero solo a otro de su misma empresa. Su asistencia
     * pasada se conserva en las sesiones del grupo anterior.
     */
    public function actualizar(Request $request, Participante $participante): RedirectResponse
    {
        $grupos = $this->gruposParaElegir($request->user(), $participante);
        $datos = $this->validar($request, $grupos, $participante);

        $participante->update($datos);

        return redirect()->route('participantes.index')->with('exito', "Se actualizó a {$participante->nombreCompleto()}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0. Su asistencia registrada se conserva.
     */
    public function darDeBaja(Participante $participante): RedirectResponse
    {
        $participante->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja a {$participante->nombreCompleto()}.");
    }

    public function reactivar(Participante $participante): RedirectResponse
    {
        if (! $participante->grupo->estado) {
            return back()->with('error', "No se puede reactivar a {$participante->nombreCompleto()}: su grupo ({$participante->grupo->nombre}) está dado de baja.");
        }

        $participante->update(['estado' => true]);

        return back()->with('exito', "Se reactivó a {$participante->nombreCompleto()}.");
    }

    /**
     * Grupos para la lista "Bloque › Grupo": activos, de bloques y empresas activos.
     * Al editar, solo los de la empresa del participante, más su grupo actual
     * (para que pueda quedarse donde está aunque ese grupo esté de baja).
     *
     * @return Collection<int, string> id_grupo => "Bloque › Grupo"
     */
    private function gruposParaElegir(Usuario $usuario, ?Participante $participante = null): Collection
    {
        $idEmpresa = $participante?->grupo->bloque->id_empresa;
        $conSigla = $usuario->esChazey() && $idEmpresa === null;

        return Grupo::visiblePara($usuario)
            ->with('bloque.empresa')
            ->where(function ($consulta) use ($idEmpresa, $participante) {
                $consulta->where('estado', true)
                    ->whereHas('bloque', fn ($q) => $q->where('estado', true)
                        ->when($idEmpresa !== null, fn ($b) => $b->where('id_empresa', $idEmpresa))
                        ->whereHas('empresa', fn ($e) => $e->where('estado', true)));

                if ($participante !== null) {
                    $consulta->orWhere('id_grupo', $participante->id_grupo);
                }
            })
            ->get()
            ->sortBy(fn (Grupo $g) => [$g->bloque->empresa->nombre, $g->bloque->nombre, $g->nombre])
            ->mapWithKeys(fn (Grupo $g) => [
                $g->id_grupo => "{$g->bloque->nombre} › {$g->nombre}".($conSigla ? " ({$g->bloque->empresa->sigla})" : ''),
            ]);
    }

    /**
     * Limpia el texto igual que la carga desde Excel (sin espacios de más; correo en minúsculas) y valida.
     */
    private function validar(Request $request, Collection $grupos, ?Participante $participante = null): array
    {
        foreach (['nombre', 'apellidos', 'rol', 'correo'] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => Texto::limpiar($request->input($campo))]);
            }
        }

        if (is_string($request->input('correo'))) {
            $request->merge(['correo' => mb_strtolower($request->input('correo'))]);
        }

        $datos = $request->validate([
            'id_grupo' => ['required', 'integer', Rule::in($grupos->keys()->all())],
            'nombre' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            // El correo no se repite en ninguna empresa, aunque el otro esté de baja.
            'correo' => ['required', 'string', 'email', 'max:255', Rule::unique('participantes', 'correo')->ignore($participante?->id_participante, 'id_participante')],
            'rol' => ['required', 'string', 'max:50'],
            // Se acepta una fecha futura (alguien que entra después), igual que en la carga.
            'fecha_ingreso' => ['nullable', 'date'],
            'comentarios' => ['nullable', 'string', 'max:10000'],
        ], [
            'id_grupo.required' => 'Elige un grupo.',
            'id_grupo.integer' => 'Elige un grupo de la lista.',
            'id_grupo.in' => $participante ? 'Elige un grupo activo de su misma empresa.' : 'Elige un grupo activo de la lista.',
            'nombre.required' => 'Escribe el nombre.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'apellidos.required' => 'Escribe los apellidos.',
            'apellidos.max' => 'Los apellidos no pueden tener más de 255 caracteres.',
            'correo.required' => 'Escribe el correo.',
            'correo.email' => 'Escribe un correo válido.',
            'correo.max' => 'El correo no puede tener más de 255 caracteres.',
            'correo.unique' => 'Ya hay un participante con este correo (puede estar dado de baja o en otra empresa).',
            'rol.required' => 'Escribe el rol.',
            'rol.max' => 'El rol no puede tener más de 50 caracteres.',
            'fecha_ingreso.date' => 'Escribe una fecha válida.',
            'comentarios.max' => 'Los comentarios no pueden tener más de 10,000 caracteres.',
        ]);

        $datos['comentarios'] = filled($datos['comentarios'] ?? null) ? trim($datos['comentarios']) : null;

        return $datos;
    }

    private function numero(Request $request, string $campo): ?int
    {
        return is_numeric($request->query($campo)) ? (int) $request->query($campo) : null;
    }
}
