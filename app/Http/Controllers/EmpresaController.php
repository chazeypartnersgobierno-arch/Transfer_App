<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmpresaController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = is_string($request->query('buscar')) ? $request->query('buscar') : '';
        $verBajas = $request->boolean('ver_bajas');

        $empresas = Empresa::visiblePara($request->user())
            ->when(! $verBajas, fn ($consulta) => $consulta->where('estado', true))
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                // addcslashes: para que % y _ se busquen como texto normal.
                $texto = '%'.addcslashes($buscar, '%_\\').'%';

                $consulta->where(fn ($q) => $q->where('nombre', 'like', $texto)->orWhere('sigla', 'like', $texto));
            })
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString(); // conserva la búsqueda al cambiar de página

        return view('empresas.index', compact('empresas', 'buscar', 'verBajas'));
    }

    public function crear(): View
    {
        return view('empresas.formulario', ['empresa' => new Empresa]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $empresa = Empresa::create($this->validar($request));

        return redirect()->route('empresas.index')->with('exito', "Se creó la empresa {$empresa->nombre}.");
    }

    public function editar(Empresa $empresa): View
    {
        return view('empresas.formulario', compact('empresa'));
    }

    public function actualizar(Request $request, Empresa $empresa): RedirectResponse
    {
        $empresa->update($this->validar($request, $empresa));

        return redirect()->route('empresas.index')->with('exito', "Se actualizó la empresa {$empresa->nombre}.");
    }

    /**
     * No se borra nada: la baja solo cambia estado a 0.
     */
    public function darDeBaja(Empresa $empresa): RedirectResponse
    {
        // Revisa cada tipo de registro que depende de la empresa y anota los que siguen activos.
        $pendientes = [];

        foreach (['usuarios', 'bloques', 'aplicativos'] as $relacion) {
            if ($empresa->{$relacion}()->where('estado', true)->exists()) {
                $pendientes[] = $relacion;
            }
        }

        if ($pendientes !== []) {
            $lista = Arr::join($pendientes, ', ', ' y ');

            return back()->with('error', "No se puede dar de baja {$empresa->nombre}: todavía tiene {$lista} activos.");
        }

        $empresa->update(['estado' => false]);

        return back()->with('exito', "Se dio de baja la empresa {$empresa->nombre}.");
    }

    public function reactivar(Empresa $empresa): RedirectResponse
    {
        $empresa->update(['estado' => true]);

        return back()->with('exito', "Se reactivó la empresa {$empresa->nombre}.");
    }

    /**
     * Reglas para crear y editar. Al editar, la sigla puede seguir siendo la misma.
     */
    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'sigla' => [
                'required',
                'string',
                'max:20',
                Rule::unique('empresas', 'sigla')->ignore($empresa?->id_empresa, 'id_empresa'),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la empresa.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
            'sigla.required' => 'Escribe la sigla de la empresa.',
            'sigla.max' => 'La sigla no puede tener más de 20 caracteres.',
            'sigla.unique' => 'Ya existe una empresa con esta sigla.',
        ]);
    }
}
