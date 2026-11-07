<?php

namespace App\Http\Controllers;

use App\Models\Bloque;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BloqueController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = is_numeric($request->query('id_empresa')) ? (int) $request->query('id_empresa') : null;

        $bloques = Bloque::visiblePara($usuario)
            ->with('empresa') // trae las empresas de una vez, sin una consulta por cada fila
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($idEmpresa !== null, fn ($consulta) => $consulta->where('id_empresa', $idEmpresa))
            // addcslashes: para que % y _ se busquen como texto normal.
            ->when($buscar !== '', fn ($consulta) => $consulta->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        // Solo Chazey ve varias empresas, así que solo a Chazey se le muestra el filtro.
        $empresas = $usuario->esChazey() ? Empresa::orderBy('nombre')->pluck('nombre', 'id_empresa') : collect();

        return view('bloques.index', compact('bloques', 'buscar', 'verBajas', 'idEmpresa', 'empresas'));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $empresas = $this->empresasParaElegir($request);

        if ($request->user()->esChazey() && $empresas->isEmpty()) {
            return redirect()->route('bloques.index')->with('error', 'Primero registra una empresa activa.');
        }

        return view('bloques.formulario', ['bloque' => new Bloque, 'empresas' => $empresas]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        // Un usuario con empresa solo crea en la suya, aunque envíe otro valor.
        if (! $request->user()->esChazey()) {
            $request->merge(['id_empresa' => $request->user()->id_empresa]);
        }

        $idEmpresa = is_numeric($request->input('id_empresa')) ? (int) $request->input('id_empresa') : 0;

        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
            'nombre' => $this->reglasDeNombre($idEmpresa),
        ], $this->mensajes());

        $bloque = Bloque::create($datos);

        return redirect()->route('bloques.index')->with('exito', "Se creó el bloque {$bloque->nombre}.");
    }

    public function editar(Bloque $bloque): View
    {
        return view('bloques.formulario', ['bloque' => $bloque, 'empresas' => collect()]);
    }

    /**
     * Solo se puede cambiar el nombre: la empresa queda fija al crearlo.
     */
    public function actualizar(Request $request, Bloque $bloque): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => $this->reglasDeNombre($bloque->id_empresa, $bloque),
        ], $this->mensajes());

        $bloque->update($datos);

        return redirect()->route('bloques.index')->with('exito', "Se actualizó el bloque {$bloque->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0.
     */
    public function darDeBaja(Bloque $bloque): RedirectResponse
    {
        $bloque->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja el bloque {$bloque->nombre}.");
    }

    public function reactivar(Bloque $bloque): RedirectResponse
    {
        if (! $bloque->empresa->estado) {
            return back()->with('error', "No se puede reactivar {$bloque->nombre}: su empresa está dada de baja.");
        }

        $bloque->update(['estado' => true]);

        return back()->with('exito', "Se reactivó el bloque {$bloque->nombre}.");
    }

    /**
     * Empresas activas para la lista del formulario. Un usuario con empresa no elige.
     */
    private function empresasParaElegir(Request $request)
    {
        return $request->user()->esChazey()
            ? Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa')
            : collect();
    }

    /**
     * El nombre no se repite dentro de una misma empresa; al editar, puede seguir siendo el mismo.
     */
    private function reglasDeNombre(int $idEmpresa, ?Bloque $bloque = null): array
    {
        return [
            'required',
            'string',
            'max:255',
            Rule::unique('bloques', 'nombre')
                ->where('id_empresa', $idEmpresa)
                ->ignore($bloque?->id_bloque, 'id_bloque'),
        ];
    }

    private function mensajes(): array
    {
        return [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
            'nombre.required' => 'Escribe el nombre del bloque.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'nombre.unique' => 'Esta empresa ya tiene un bloque con este nombre.',
        ];
    }
}
