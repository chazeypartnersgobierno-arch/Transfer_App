<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActividadController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = $this->numeroDeLaUrl($request, 'id_empresa');
        $idFase = $this->numeroDeLaUrl($request, 'id_fase');

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra ese filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();
        $fases = Fase::orderBy('orden')->pluck('nombre', 'id_fase');

        // Si no se eligió empresa, Chazey ve bloques de varias: la sigla ayuda a distinguirlos.
        $conSigla = $usuario->esChazey() && $idEmpresa === null;
        $bloques = Bloque::visiblePara($usuario)
            ->with('empresa')
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Bloque $b) => [$b->id_bloque => $conSigla ? "{$b->nombre} ({$b->empresa->sigla})" : $b->nombre]);

        // Un bloque que ya no está en la lista (por ejemplo, al cambiar de empresa) se ignora.
        $idBloque = $this->numeroDeLaUrl($request, 'id_bloque');
        $idBloque = $idBloque !== null && $bloques->has($idBloque) ? $idBloque : null;

        $actividades = Actividad::visiblePara($usuario)
            ->with(['empresa', 'fase', 'bloque']) // trae todo de una vez, sin una consulta por cada fila
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            ->when($idFase !== null, fn ($consulta) => $consulta->where('id_fase', $idFase))
            ->when($idBloque !== null, fn ($consulta) => $consulta->where('id_bloque', $idBloque))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';

                $consulta->where(fn ($q) => $q
                    ->where('nombre', 'like', $texto)
                    ->orWhere('macroproceso', 'like', $texto)
                    ->orWhere('proceso', 'like', $texto)
                    ->orWhere('subproceso', 'like', $texto));
            })
            // Primero por el orden de la fase y luego por nombre.
            ->orderBy(Fase::select('orden')->whereColumn('fases.id_fase', 'actividades.id_fase'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('actividades.index', compact('actividades', 'buscar', 'verBajas', 'idEmpresa', 'idFase', 'idBloque', 'empresas', 'fases', 'bloques'));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $usuario = $request->user();

        $empresas = $usuario->esChazey()
            ? Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa')
            : collect();

        if ($usuario->esChazey() && $empresas->isEmpty()) {
            return redirect()->route('actividades.index')->with('error', 'Primero registra una empresa activa.');
        }

        // Fases de la base de datos, para saber en pantalla cuál no lleva bloque.
        $fases = Fase::orderBy('orden')->get();

        // Bloques activos de empresas activas. app.js muestra solo los de la empresa elegida.
        $bloques = Bloque::visiblePara($usuario)
            ->where('estado', true)
            ->whereHas('empresa', fn ($consulta) => $consulta->where('estado', true))
            ->orderBy('nombre')
            ->get();

        return view('actividades.formulario', [
            'actividad' => new Actividad,
            'empresas' => $empresas,
            'fases' => $fases,
            'bloques' => $bloques,
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        // Un usuario con empresa solo crea en la suya, aunque envíe otro valor.
        if (! $request->user()->esChazey()) {
            $request->merge(['id_empresa' => $request->user()->id_empresa]);
        }

        $idEmpresa = is_numeric($request->input('id_empresa')) ? (int) $request->input('id_empresa') : 0;
        $fase = is_numeric($request->input('id_fase')) ? Fase::find((int) $request->input('id_fase')) : null;

        // Onboarding no lleva bloque, aunque se envíe uno. Las demás fases lo exigen.
        if ($fase?->esOnboarding()) {
            $request->merge(['id_bloque' => null]);
        }

        $reglaDeBloque = $fase?->esOnboarding()
            ? ['nullable']
            : [
                'required',
                'integer',
                // Debe estar activo y ser de la misma empresa.
                Rule::exists('bloques', 'id_bloque')->where('id_empresa', $idEmpresa)->where('estado', true),
            ];

        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
            'id_fase' => ['required', 'integer', Rule::exists('fases', 'id_fase')],
            'id_bloque' => $reglaDeBloque,
            ...$this->reglasDeTextos(),
        ], $this->mensajes());

        $this->validarQueNoSeRepita($datos);

        $actividad = Actividad::create($datos);

        return redirect()->route('actividades.index')->with('exito', "Se creó la actividad {$actividad->nombre}.");
    }

    public function editar(Actividad $actividad): View
    {
        return view('actividades.formulario', [
            'actividad' => $actividad,
            'empresas' => collect(),
            'fases' => collect(),
            'bloques' => collect(),
        ]);
    }

    /**
     * Solo se pueden cambiar los textos: empresa, fase y bloque quedan fijos al crearla.
     */
    public function actualizar(Request $request, Actividad $actividad): RedirectResponse
    {
        $datos = $request->validate($this->reglasDeTextos(), $this->mensajes());

        $this->validarQueNoSeRepita([
            ...$datos,
            'id_empresa' => $actividad->id_empresa,
            'id_fase' => $actividad->id_fase,
            'id_bloque' => $actividad->id_bloque,
        ], $actividad);

        $actividad->update($datos);

        return redirect()->route('actividades.index')->with('exito', "Se actualizó la actividad {$actividad->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0.
     */
    public function darDeBaja(Actividad $actividad): RedirectResponse
    {
        $actividad->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja la actividad {$actividad->nombre}.");
    }

    public function reactivar(Actividad $actividad): RedirectResponse
    {
        if (! $actividad->empresa->estado) {
            return back()->with('error', "No se puede reactivar {$actividad->nombre}: su empresa está dada de baja.");
        }

        // Las actividades de Onboarding no tienen bloque.
        if ($actividad->bloque && ! $actividad->bloque->estado) {
            return back()->with('error', "No se puede reactivar {$actividad->nombre}: su bloque está dado de baja.");
        }

        $actividad->update(['estado' => true]);

        return back()->with('exito', "Se reactivó la actividad {$actividad->nombre}.");
    }

    /**
     * Cada actividad se registra una sola vez. La tabla no lo impide por sí sola, así que
     * se revisa aquí: se repite si coinciden empresa, fase, bloque, procesos y nombre,
     * incluso con una dada de baja.
     */
    private function validarQueNoSeRepita(array $datos, ?Actividad $actividad = null): void
    {
        $repetida = Actividad::query()
            ->where('id_empresa', $datos['id_empresa'])
            ->where('id_fase', $datos['id_fase'])
            ->where('id_bloque', $datos['id_bloque'] ?? null)
            ->where('macroproceso', $datos['macroproceso'] ?? null)
            ->where('proceso', $datos['proceso'] ?? null)
            ->where('subproceso', $datos['subproceso'] ?? null)
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
            'proceso' => ['nullable', 'string', 'max:255'],
            'subproceso' => ['nullable', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:500'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
            'id_fase.required' => 'Elige una fase.',
            'id_fase.integer' => 'Elige una fase de la lista.',
            'id_fase.exists' => 'Elige una fase de la lista.',
            'id_bloque.required' => 'Elige un bloque.',
            'id_bloque.integer' => 'Elige un bloque de la lista.',
            'id_bloque.exists' => 'El bloque no existe, está dado de baja o es de otra empresa.',
            'macroproceso.max' => 'El macroproceso no puede tener más de 255 caracteres.',
            'proceso.max' => 'El proceso no puede tener más de 255 caracteres.',
            'subproceso.max' => 'El subproceso no puede tener más de 255 caracteres.',
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
