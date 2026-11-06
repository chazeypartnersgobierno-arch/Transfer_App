<?php

namespace App\Http\Controllers;

use App\Models\Fase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FaseController extends Controller
{
    public function index(): View
    {
        return view('fases.index', ['fases' => Fase::orderBy('orden')->get()]);
    }

    public function editar(Fase $fase): View
    {
        return view('fases.formulario', compact('fase'));
    }

    /**
     * Solo se puede cambiar el nombre: el orden y las sesiones no se leen del formulario.
     */
    public function actualizar(Request $request, Fase $fase): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('fases', 'nombre')->ignore($fase->id_fase, 'id_fase'),
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la fase.',
            'nombre.max' => 'El nombre no puede tener más de 100 caracteres.',
            'nombre.unique' => 'Ya existe una fase con este nombre.',
        ]);

        $fase->update($datos);

        return redirect()->route('fases.index')->with('exito', "Se actualizó la fase {$fase->nombre}.");
    }
}
