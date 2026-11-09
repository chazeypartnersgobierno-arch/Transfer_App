<?php

namespace App\Http\Controllers;

use App\Exports\PlantillaDeCarga;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Carga desde Excel. Solo admin: la ruta está protegida con el Gate administrar.
 */
class CargaController extends Controller
{
    public function index(Request $request): View
    {
        // Solo Chazey elige empresa; un usuario con empresa trabaja con la suya.
        $empresas = $request->user()->esChazey()
            ? Empresa::where('estado', true)->orderBy('nombre')->pluck('nombre', 'id_empresa')
            : collect();

        return view('carga.index', [
            'empresas' => $empresas,
            'empresaPropia' => $request->user()->empresa,
        ]);
    }

    public function plantilla(Request $request): BinaryFileResponse|RedirectResponse
    {
        $empresa = $this->empresaElegida($request);

        if ($empresa === null) {
            return redirect()->route('carga.index')->with('error', 'Tu empresa está dada de baja: no se puede generar la plantilla.');
        }

        $archivo = 'plantilla_carga_'.Str::slug($empresa->sigla, '_').'.xlsx';

        return Excel::download(new PlantillaDeCarga($empresa), $archivo);
    }

    /**
     * Empresa activa con la que se trabaja: la elegida por Chazey o la del usuario.
     */
    private function empresaElegida(Request $request): ?Empresa
    {
        if (! $request->user()->esChazey()) {
            return Empresa::where('estado', true)->find($request->user()->id_empresa);
        }

        $datos = $request->validate([
            'id_empresa' => ['required', 'integer', Rule::exists('empresas', 'id_empresa')->where('estado', true)],
        ], [
            'id_empresa.required' => 'Elige una empresa.',
            'id_empresa.integer' => 'Elige una empresa de la lista.',
            'id_empresa.exists' => 'La empresa no existe o está dada de baja.',
        ]);

        return Empresa::find($datos['id_empresa']);
    }
}
