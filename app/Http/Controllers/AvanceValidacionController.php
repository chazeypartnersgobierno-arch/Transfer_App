<?php

namespace App\Http\Controllers;

use App\Models\AvanceValidacion;
use App\Models\Seguimiento;
use App\Services\AvanceDeFases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Avances de validación de un seguimiento. Admin y consultor (Gate dar-seguimiento).
 *
 * - Solo en fases sin sesiones (tiene_sesiones = 0, es decir, Validación) y habilitadas para el grupo (ver AvanceDeFases).
 * - Se captura un grupo (un seguimiento) a la vez.
 * - La fecha de corte debe estar entre la fecha de inicio y la fecha fin del seguimiento y no puede ser futura.
 * - Una sola captura por seguimiento y fecha de corte: si ya existe, se corrige editando la existente.
 * - No se borran: se corrigen editándolos. id_usuario queda con quien hizo la última captura o corrección.
 */
class AvanceValidacionController extends Controller
{
    public function crear(Seguimiento $seguimiento): View|RedirectResponse
    {
        if ($motivo = $this->motivoParaNoCapturar($seguimiento)) {
            return redirect()->route('seguimientos.editar', $seguimiento)->with('error', $motivo);
        }

        return view('avances.formulario', ['avance' => new AvanceValidacion, 'seguimiento' => $seguimiento, 'bloqueo' => null]);
    }

    public function guardar(Request $request, Seguimiento $seguimiento): RedirectResponse
    {
        if ($motivo = $this->motivoParaNoCapturar($seguimiento)) {
            return redirect()->route('seguimientos.editar', $seguimiento)->with('error', $motivo);
        }

        $datos = $this->validar($request, $seguimiento);

        $seguimiento->avances()->create([...$datos, 'id_usuario' => $request->user()->id_usuario]);

        return redirect()->route('seguimientos.editar', $seguimiento)
            ->with('exito', "Se registró el avance con corte al {$this->fechaCorta($datos['fecha_corte'])}.");
    }

    /**
     * Si el grupo o la actividad están de baja, la misma pantalla sirve solo para consultar.
     */
    public function editar(AvanceValidacion $avance): View
    {
        $avance->load('usuario');

        return view('avances.formulario', [
            'avance' => $avance,
            'seguimiento' => $avance->seguimiento,
            'bloqueo' => app(AvanceDeFases::class)->bloqueoDe($avance->seguimiento),
        ]);
    }

    public function actualizar(Request $request, AvanceValidacion $avance): RedirectResponse
    {
        $seguimiento = $avance->seguimiento;

        if (! $seguimiento->estaEnTrabajo()) {
            return redirect()->route('avances.editar', $avance)
                ->with('error', 'Este avance es de un grupo o una actividad dados de baja: solo se puede consultar.');
        }

        if ($bloqueo = app(AvanceDeFases::class)->bloqueoDe($seguimiento)) {
            return redirect()->route('avances.editar', $avance)->with('error', $bloqueo);
        }

        if (! $seguimiento->tieneFechas()) {
            return redirect()->route('avances.editar', $avance)
                ->with('error', 'Primero captura la fecha de inicio y la fecha fin del seguimiento.');
        }

        $datos = $this->validar($request, $seguimiento, $avance);

        // Quien corrige queda como el usuario del avance.
        $avance->update([...$datos, 'id_usuario' => $request->user()->id_usuario]);

        return redirect()->route('seguimientos.editar', $seguimiento)
            ->with('exito', "Se actualizó el avance con corte al {$this->fechaCorta($datos['fecha_corte'])}.");
    }

    /**
     * Por qué no se pueden capturar avances en este seguimiento, o null si sí se puede.
     * La lógica usa tiene_sesiones, nunca el nombre de la fase.
     */
    private function motivoParaNoCapturar(Seguimiento $seguimiento): ?string
    {
        if ($seguimiento->tieneSesiones()) {
            return "La fase {$seguimiento->fase->nombre} registra sesiones, no avances de validación.";
        }

        if (! $seguimiento->estaEnTrabajo()) {
            return 'Este seguimiento es de un grupo o una actividad dados de baja: solo se puede consultar.';
        }

        if ($bloqueo = app(AvanceDeFases::class)->bloqueoDe($seguimiento)) {
            return $bloqueo;
        }

        if (! $seguimiento->tieneFechas()) {
            return 'Primero captura la fecha de inicio y la fecha fin del seguimiento: los avances deben quedar dentro de esas fechas.';
        }

        return null;
    }

    /**
     * Valida el formulario y las reglas que dependen del seguimiento. $actual es el avance
     * que se está editando (para no compararlo consigo mismo al buscar la fecha repetida).
     */
    private function validar(Request $request, Seguimiento $seguimiento, ?AvanceValidacion $actual = null): array
    {
        $datos = $request->validate([
            // before_or_equal:today: no se aceptan cortes futuros.
            'fecha_corte' => ['required', 'date', 'before_or_equal:today'],
            'porcentaje_esperado' => ['required', 'integer', Rule::in(AvanceValidacion::PORCENTAJES_ESPERADOS)],
            'porcentaje_real' => ['required', 'integer', 'between:0,100'],
            'comentarios' => ['nullable', 'string', 'max:10000'],
        ], [
            'fecha_corte.required' => 'Escribe la fecha de corte.',
            'fecha_corte.date' => 'Escribe una fecha de corte válida.',
            'fecha_corte.before_or_equal' => 'La fecha de corte no puede ser futura.',
            'porcentaje_esperado.required' => 'Elige el % esperado.',
            'porcentaje_esperado.integer' => 'Elige un % esperado de la lista.',
            'porcentaje_esperado.in' => 'Elige un % esperado de la lista.',
            'porcentaje_real.required' => 'Escribe el % real.',
            'porcentaje_real.integer' => 'El % real debe ser un número entero de 0 a 100.',
            'porcentaje_real.between' => 'El % real debe ser un número entero de 0 a 100.',
            'comentarios.max' => 'Los comentarios no pueden tener más de 10,000 caracteres.',
        ]);

        if (! $seguimiento->fechaDentroDelRango($datos['fecha_corte'])) {
            throw ValidationException::withMessages([
                'fecha_corte' => "La fecha de corte debe estar entre las fechas del seguimiento ({$seguimiento->rangoDeFechas()}).",
            ]);
        }

        // Una sola captura por fecha de corte: la existente se corrige editándola.
        $existente = $seguimiento->avances()
            ->whereDate('fecha_corte', $datos['fecha_corte'])
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id_avance_validacion))
            ->first();

        if ($existente !== null) {
            // El aviso trae el id del existente para que la pantalla ofrezca abrirlo.
            session()->flash('avance_existente', $existente->id_avance_validacion);

            throw ValidationException::withMessages([
                'fecha_corte' => "Ya hay un avance con corte al {$this->fechaCorta($datos['fecha_corte'])} en este seguimiento. Corrígelo editando ese avance.",
            ]);
        }

        $comentarios = trim((string) ($datos['comentarios'] ?? ''));

        return [
            'fecha_corte' => $datos['fecha_corte'],
            'porcentaje_esperado' => (int) $datos['porcentaje_esperado'],
            'porcentaje_real' => (int) $datos['porcentaje_real'],
            'comentarios' => $comentarios === '' ? null : $comentarios,
        ];
    }

    private function fechaCorta(string $fecha): string
    {
        return Carbon::parse($fecha)->format('d/m/Y');
    }
}
