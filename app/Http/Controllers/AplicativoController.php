<?php

namespace App\Http\Controllers;

use App\Models\Aplicativo;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AplicativoController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');
        $idEmpresa = is_numeric($request->query('id_empresa')) ? (int) $request->query('id_empresa') : null;

        $aplicativos = Aplicativo::visiblePara($usuario)
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

        return view('aplicativos.index', compact('aplicativos', 'buscar', 'verBajas', 'idEmpresa', 'empresas'));
    }

    public function crear(Request $request): View|RedirectResponse
    {
        $empresas = $this->empresasParaElegir($request);

        if ($request->user()->esChazey() && $empresas->isEmpty()) {
            return redirect()->route('aplicativos.index')->with('error', 'Primero registra una empresa activa.');
        }

        return view('aplicativos.formulario', ['aplicativo' => new Aplicativo, 'empresas' => $empresas]);
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

        $aplicativo = Aplicativo::create($datos);

        return redirect()->route('aplicativos.index')->with('exito', "Se creó el aplicativo {$aplicativo->nombre}.");
    }

    public function editar(Aplicativo $aplicativo): View
    {
        return view('aplicativos.formulario', ['aplicativo' => $aplicativo, 'empresas' => collect()]);
    }

    /**
     * Solo se puede cambiar el nombre: la empresa queda fija al crearlo.
     */
    public function actualizar(Request $request, Aplicativo $aplicativo): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => $this->reglasDeNombre($aplicativo->id_empresa, $aplicativo),
        ], $this->mensajes());

        $aplicativo->update($datos);

        return redirect()->route('aplicativos.index')->with('exito', "Se actualizó el aplicativo {$aplicativo->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0.
     */
    public function darDeBaja(Aplicativo $aplicativo): RedirectResponse
    {
        $aplicativo->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja el aplicativo {$aplicativo->nombre}.");
    }

    public function reactivar(Aplicativo $aplicativo): RedirectResponse
    {
        if (! $aplicativo->empresa->estado) {
            return back()->with('error', "No se puede reactivar {$aplicativo->nombre}: su empresa está dada de baja.");
        }

        $aplicativo->update(['estado' => true]);

        return back()->with('exito', "Se reactivó el aplicativo {$aplicativo->nombre}.");
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
    private function reglasDeNombre(int $idEmpresa, ?Aplicativo $aplicativo = null): array
    {
        return [
            'required',
            'string',
            'max:255',
            Rule::unique('aplicativos', 'nombre')
                ->where('id_empresa', $idEmpresa)
                ->ignore($aplicativo?->id_aplicativo, 'id_aplicativo'),
        ];
    }

    private function mensajes(): array
    {
        return [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
            'nombre.required' => 'Escribe el nombre del aplicativo.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'nombre.unique' => 'Esta empresa ya tiene un aplicativo con este nombre.',
        ];
    }
}
