<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Services\GeneradorDeSeguimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GrupoController extends Controller
{
    public function index(Request $request, GeneradorDeSeguimientos $generador): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = is_numeric($request->query('id_empresa')) ? (int) $request->query('id_empresa') : null;

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();

        // Si no se eligió empresa, Chazey ve bloques de varias: la sigla ayuda a distinguirlos.
        $conSigla = $usuario->esChazey() && $idEmpresa === null;
        $bloques = Bloque::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Bloque $b) => [$b->id_bloque => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);

        // Un bloque que ya no está en la lista (por ejemplo, al cambiar de empresa) se ignora.
        $idBloque = is_numeric($request->query('id_bloque')) ? (int) $request->query('id_bloque') : null;
        $idBloque = $idBloque !== null && $bloques->has($idBloque) ? $idBloque : null;

        $grupos = Grupo::visiblePara($usuario)
            ->with('bloque.empresa') // trae bloque y empresa de una vez, sin una consulta por cada fila
            ->withCount('seguimientos') // agrega seguimientos_count a cada grupo
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->whereHas('bloque', fn ($q) => $q->where('id_empresa', $idEmpresa)))
            ->when($idBloque !== null, fn ($consulta) => $consulta->where('id_bloque', $idBloque))
            // addcslashes: para que % y _ se busquen como texto normal.
            ->when($buscar !== '', fn ($consulta) => $consulta->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        // Para avisar, antes de reactivar, cuántos seguimientos se generarían.
        $faltantes = $grupos->getCollection()
            ->reject(fn (Grupo $grupo) => $grupo->estado)
            ->mapWithKeys(fn (Grupo $grupo) => [$grupo->id_grupo => $generador->faltantesDeGrupo($grupo)->count()]);

        return view('grupos.index', compact('grupos', 'buscar', 'verBajas', 'idEmpresa', 'idBloque', 'empresas', 'bloques', 'faltantes'));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $bloques = $this->bloquesParaElegir($request);

        if ($bloques->isEmpty()) {
            return redirect()->route('grupos.index')->with('error', 'Primero registra un proceso activo.');
        }

        return view('grupos.formulario', [
            'grupo' => new Grupo,
            'opcionesDeBloque' => $this->opcionesDeBloque($request, $bloques),
        ]);
    }

    public function guardar(Request $request, GeneradorDeSeguimientos $generador): RedirectResponse
    {
        $idBloque = is_numeric($request->input('id_bloque')) ? (int) $request->input('id_bloque') : 0;

        $datos = $request->validate([
            // Solo los bloques que este usuario puede elegir: activos y de su alcance.
            'id_bloque' => ['required', 'integer', Rule::in($this->bloquesParaElegir($request)->pluck('id_bloque'))],
            'nombre' => $this->reglasDeNombre($idBloque),
        ], $this->mensajes());

        // Todo o nada: si falla la generación, tampoco queda el grupo.
        [$grupo, $generados] = DB::transaction(function () use ($datos, $generador) {
            $grupo = Grupo::create($datos);

            // create() no trae el valor por defecto de la base de datos.
            $grupo->refresh();

            return [$grupo, $generador->generarParaGrupo($grupo)];
        });

        $detalle = $generados > 0 ? 'con '.Seguimiento::textoCantidad($generados) : 'sin seguimientos todavía';

        return redirect()->route('grupos.index')->with('exito', "Se creó el grupo {$grupo->nombre} {$detalle}.");
    }

    public function editar(Grupo $grupo): View
    {
        return view('grupos.formulario', ['grupo' => $grupo, 'opcionesDeBloque' => collect()]);
    }

    /**
     * Solo se puede cambiar el nombre. Si el bloque está mal, se da de baja y se crea otro grupo.
     */
    public function actualizar(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => $this->reglasDeNombre($grupo->id_bloque, $grupo),
        ], $this->mensajes());

        $grupo->update($datos);

        return redirect()->route('grupos.index')->with('exito', "Se actualizó el grupo {$grupo->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0. Sus seguimientos quedan intactos.
     */
    public function darDeBaja(Grupo $grupo): RedirectResponse
    {
        $grupo->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja el grupo {$grupo->nombre}. Sus seguimientos quedaron intactos.");
    }

    /**
     * Al reactivar se generan los seguimientos que falten; los existentes no cambian.
     */
    public function reactivar(Grupo $grupo, GeneradorDeSeguimientos $generador): RedirectResponse
    {
        if (! $grupo->bloque->estado) {
            return back()->with('error', "No se puede reactivar {$grupo->nombre}: su proceso está dado de baja.");
        }

        $generados = DB::transaction(function () use ($grupo, $generador) {
            $grupo->update(['estado' => true]);

            return $generador->generarParaGrupo($grupo);
        });

        $detalle = $generados > 0 ? ' '.Seguimiento::seGeneraron($generados).'.' : '';

        return back()->with('exito', "Se reactivó el grupo {$grupo->nombre}.{$detalle}");
    }

    /**
     * Bloques activos de empresas activas que este usuario puede usar.
     */
    private function bloquesParaElegir(Request $request)
    {
        return Bloque::visiblePara($request->user())
            ->with('empresa')
            ->where('estado', true)
            ->whereHas('empresa', fn ($consulta) => $consulta->where('estado', true))
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Texto de cada bloque en la lista. Chazey ve varias empresas: la sigla ayuda a distinguirlos.
     */
    private function opcionesDeBloque(Request $request, $bloques)
    {
        $conSigla = $request->user()->esChazey();

        return $bloques->mapWithKeys(fn (Bloque $b) => [$b->id_bloque => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);
    }

    /**
     * El nombre no se repite dentro de un mismo bloque; al editar, puede seguir siendo el mismo.
     */
    private function reglasDeNombre(int $idBloque, ?Grupo $grupo = null): array
    {
        return [
            'required',
            'string',
            'max:255',
            Rule::unique('grupos', 'nombre')
                ->where('id_bloque', $idBloque)
                ->ignore($grupo?->id_grupo, 'id_grupo'),
        ];
    }

    private function mensajes(): array
    {
        return [
            'id_bloque.required' => 'Elige un proceso.',
            'id_bloque.integer' => 'Elige un proceso de la lista.',
            'id_bloque.in' => 'El proceso no existe, está dado de baja o es de otra empresa.',
            'nombre.required' => 'Escribe el nombre del grupo.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'nombre.unique' => 'Este proceso ya tiene un grupo con este nombre.',
        ];
    }
}
