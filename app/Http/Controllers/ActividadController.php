<?php

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Seguimiento;
use App\Services\GeneradorDeSeguimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActividadController extends Controller
{
    public function index(Request $request, GeneradorDeSeguimientos $generador): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = $this->numeroDeLaUrl($request, 'id_empresa');
        $tipo = is_string($request->query('tipo')) ? TipoActividad::tryFrom($request->query('tipo')) : null;

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();

        // Si no se eligió empresa, Chazey ve procesos de varias: la sigla ayuda a distinguirlos.
        $conSigla = $usuario->esChazey() && $idEmpresa === null;
        $procesos = Proceso::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Proceso $b) => [$b->id_proceso => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);

        // Un proceso que ya no está en la lista (por ejemplo, al cambiar de empresa) se ignora.
        $idProceso = $this->numeroDeLaUrl($request, 'id_proceso');
        $idProceso = $idProceso !== null && $procesos->has($idProceso) ? $idProceso : null;

        $actividades = Actividad::visiblePara($usuario)
            ->with(['empresa', 'proceso']) // trae todo de una vez, sin una consulta por cada fila
            ->withCount('seguimientos') // agrega seguimientos_count a cada actividad
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->when($tipo !== null, fn ($consulta) => $consulta->where('tipo', $tipo))
            ->when($idProceso !== null, fn ($consulta) => $consulta->where('id_proceso', $idProceso))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';

                // También se busca por el nombre del proceso.
                $consulta->where(fn ($q) => $q
                    ->where('nombre', 'like', $texto)
                    ->orWhere('macroproceso', 'like', $texto)
                    ->orWhereHas('proceso', fn ($b) => $b->where('nombre', 'like', $texto)));
            })
            // En el orden en que se cargaron.
            ->orderBy('id_actividad')
            ->paginate(15)
            ->withQueryString();

        // Para avisar, antes de reactivar, cuántos seguimientos se generarían.
        $faltantes = $actividades->getCollection()
            ->reject(fn (Actividad $actividad) => $actividad->estado)
            ->mapWithKeys(fn (Actividad $actividad) => [$actividad->id_actividad => $generador->faltantesDeActividad($actividad)->count()]);

        return view('actividades.index', compact('actividades', 'buscar', 'verBajas', 'idEmpresa', 'tipo', 'idProceso', 'empresas', 'procesos', 'faltantes'));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $usuario = $request->user();

        // Solo el admin (siempre de Chazey) crea: elige entre las empresas activas.
        $empresas = Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa');

        if ($empresas->isEmpty()) {
            return redirect()->route('actividades.index')->with('error', 'Primero registra una empresa activa.');
        }

        // Procesos activos de empresas activas. app.js muestra solo los de la empresa elegida.
        $procesos = Proceso::visiblePara($usuario)
            ->where('estado', true)
            ->whereHas('empresa', fn ($consulta) => $consulta->where('estado', true))
            ->orderBy('nombre')
            ->get();

        return view('actividades.formulario', [
            'actividad' => new Actividad,
            'empresas' => $empresas,
            'procesos' => $procesos,
        ]);
    }

    /**
     * Se guarda en dos pasos cuando la actividad generaría seguimientos: primero se avisa
     * cuántos serían y solo se guarda si el usuario confirma (accion = confirmar).
     */
    public function guardar(Request $request, GeneradorDeSeguimientos $generador): View|RedirectResponse
    {
        // "Corregir" vuelve al formulario con lo que había escrito.
        if ($request->input('accion') === 'corregir') {
            return redirect()->route('actividades.crear')->withInput($request->except('accion', '_token'));
        }

        $idEmpresa = is_numeric($request->input('id_empresa')) ? (int) $request->input('id_empresa') : 0;

        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
            'tipo' => ['required', Rule::enum(TipoActividad::class)],
            // Toda actividad lleva proceso: debe estar activo y ser de la misma empresa.
            'id_proceso' => [
                'required',
                'integer',
                Rule::exists('procesos', 'id_proceso')->where('id_empresa', $idEmpresa)->where('estado', true),
            ],
            ...$this->reglasDeTextos(),
        ], $this->mensajes());

        $this->validarQueNoSeRepita($datos);

        $porGenerar = $generador->faltantesDeActividad(new Actividad($datos))->count();

        if ($porGenerar > 0 && $request->input('accion') !== 'confirmar') {
            return view('actividades.confirmar', [
                'datos' => $datos,
                'porGenerar' => $porGenerar,
                'empresa' => Empresa::find($datos['id_empresa']),
                'proceso' => Proceso::find($datos['id_proceso']),
            ]);
        }

        // Todo o nada: si falla la generación, tampoco queda la actividad.
        [$actividad, $generados] = DB::transaction(function () use ($datos, $generador) {
            $actividad = Actividad::create($datos);

            // create() no trae el valor por defecto de la base de datos.
            $actividad->refresh();

            return [$actividad, $generador->generarParaActividad($actividad)];
        });

        $detalle = $generados > 0 ? ' '.Seguimiento::seGeneraron($generados).'.' : '';

        return redirect()->route('actividades.index')->with('exito', "Se creó la actividad {$actividad->nombre}.{$detalle}");
    }

    public function editar(Actividad $actividad): View
    {
        return view('actividades.formulario', [
            'actividad' => $actividad,
            'empresas' => collect(),
            'procesos' => collect(),
        ]);
    }

    /**
     * Solo se pueden cambiar los textos: empresa, tipo y proceso quedan fijos al crearla.
     */
    public function actualizar(Request $request, Actividad $actividad): RedirectResponse
    {
        $datos = $request->validate($this->reglasDeTextos(), $this->mensajes());

        $this->validarQueNoSeRepita([
            ...$datos,
            'id_empresa' => $actividad->id_empresa,
            'tipo' => $actividad->tipo->value,
            'id_proceso' => $actividad->id_proceso,
        ], $actividad);

        $actividad->update($datos);

        return redirect()->route('actividades.index')->with('exito', "Se actualizó la actividad {$actividad->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0. Sus seguimientos quedan intactos.
     */
    public function darDeBaja(Actividad $actividad): RedirectResponse
    {
        $actividad->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja la actividad {$actividad->nombre}. Sus seguimientos quedaron intactos.");
    }

    /**
     * Al reactivar se generan los seguimientos que falten; los existentes no cambian.
     */
    public function reactivar(Actividad $actividad, GeneradorDeSeguimientos $generador): RedirectResponse
    {
        if (! $actividad->empresa->estado) {
            return back()->with('error', "No se puede reactivar {$actividad->nombre}: su empresa está dada de baja.");
        }

        if (! $actividad->proceso->estado) {
            return back()->with('error', "No se puede reactivar {$actividad->nombre}: su proceso está dado de baja.");
        }

        $generados = DB::transaction(function () use ($actividad, $generador) {
            $actividad->update(['estado' => true]);

            return $generador->generarParaActividad($actividad);
        });

        $detalle = $generados > 0 ? ' '.Seguimiento::seGeneraron($generados).'.' : '';

        return back()->with('exito', "Se reactivó la actividad {$actividad->nombre}.{$detalle}");
    }

    /**
     * Cada actividad se registra una sola vez. La tabla no lo impide por sí sola, así que
     * se revisa aquí: se repite si coinciden empresa, tipo, proceso, macroproceso y nombre,
     * incluso con una dada de baja.
     */
    private function validarQueNoSeRepita(array $datos, ?Actividad $actividad = null): void
    {
        $repetida = Actividad::query()
            ->where('id_empresa', $datos['id_empresa'])
            ->where('tipo', $datos['tipo'])
            ->where('id_proceso', $datos['id_proceso'])
            ->where('macroproceso', $datos['macroproceso'] ?? null)
            ->where('nombre', $datos['nombre'])
            ->when($actividad, fn ($consulta) => $consulta->whereKeyNot($actividad->id_actividad))
            ->exists();

        if ($repetida) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe una actividad igual. Si está dada de baja, puedes reactivarla.',
            ]);
        }
    }

    private function reglasDeTextos(): array
    {
        return [
            'macroproceso' => ['nullable', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:500'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
            'tipo.required' => 'Elige un tipo.',
            'tipo.enum' => 'Elige un tipo de la lista.',
            'id_proceso.required' => 'Elige un proceso.',
            'id_proceso.integer' => 'Elige un proceso de la lista.',
            'id_proceso.exists' => 'El proceso no existe, está dado de baja o es de otra empresa.',
            'macroproceso.max' => 'El macroproceso no puede tener más de 255 caracteres.',
            'nombre.required' => 'Escribe el nombre de la actividad.',
            'nombre.max' => 'El nombre no puede tener más de 500 caracteres.',
        ];
    }

    /**
     * Lee un filtro numérico de la URL; cualquier otro valor se ignora.
     */
    private function numeroDeLaUrl(Request $request, string $nombre): ?int
    {
        return is_numeric($request->query($nombre)) ? (int) $request->query($nombre) : null;
    }
}
