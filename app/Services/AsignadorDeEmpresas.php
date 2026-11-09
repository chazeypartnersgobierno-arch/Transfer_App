<?php

namespace App\Services;

use App\Enums\RolApp;
use App\Models\AsignacionEmpresa;
use App\Models\Empresa;
use App\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único lugar donde se crean y se cierran asignaciones de consultores a empresas.
 * Todo pasa en una transacción: o se hace completo o no se hace nada.
 *
 * Regla actual: un consultor tiene UNA sola asignación activa. Vive solo en
 * cerrarLasAnteriores(). Para permitir varias, se quita esa llamada en asignar():
 * la visibilidad (Usuario::idsEmpresasVisibles) ya trabaja con una lista de empresas.
 */
class AsignadorDeEmpresas
{
    /**
     * Asigna el consultor a la empresa y cierra la asignación que tenía.
     *
     * @throws ValidationException si no se puede asignar (el mensaje va en el campo id_empresa).
     */
    public function asignar(Usuario $usuario, Empresa $empresa, CarbonInterface $fechaInicio): AsignacionEmpresa
    {
        return DB::transaction(function () use ($usuario, $empresa, $fechaInicio) {
            // Bloquea la fila del usuario: si dos admins asignan al mismo tiempo, el segundo
            // espera al primero y nunca quedan dos asignaciones activas.
            Usuario::whereKey($usuario->id_usuario)->lockForUpdate()->first();

            if ($usuario->rol_app !== RolApp::Consultor) {
                throw ValidationException::withMessages(['id_empresa' => 'Solo los consultores tienen empresa asignada.']);
            }

            if (! $empresa->estado) {
                throw ValidationException::withMessages(['id_empresa' => "La empresa {$empresa->nombre} está dada de baja."]);
            }

            if ($usuario->asignaciones()->activas()->where('id_empresa', $empresa->id_empresa)->exists()) {
                throw ValidationException::withMessages(['id_empresa' => "Ya trabaja con {$empresa->nombre}."]);
            }

            if ($fechaInicio->isAfter(today())) {
                throw ValidationException::withMessages(['fecha_inicio' => 'La fecha no puede ser futura.']);
            }

            $this->cerrarLasAnteriores($usuario, $fechaInicio);

            return $usuario->asignaciones()->create([
                'id_empresa' => $empresa->id_empresa,
                'fecha_inicio' => $fechaInicio->toDateString(),
                'activa' => true,
            ]);
        });
    }

    /**
     * Cierra una asignación sin abrir otra: el consultor queda sin empresa (fecha_fin = hoy).
     */
    public function cerrar(AsignacionEmpresa $asignacion): void
    {
        if ($asignacion->activa) {
            $asignacion->update(['activa' => false, 'fecha_fin' => today()]);
        }
    }

    /**
     * Cierra todas las asignaciones activas del usuario con fecha de hoy (por ejemplo, al pasar a admin).
     */
    public function cerrarTodas(Usuario $usuario): void
    {
        $usuario->asignaciones()->activas()->update(['activa' => false, 'fecha_fin' => today()->toDateString()]);
    }

    /**
     * REGLA: una sola asignación activa por consultor. Quitar esta llamada permite varias.
     *
     * La anterior se cierra un día antes de que empiece la nueva, para que los periodos
     * no se traslapen aunque la nueva se registre con una fecha pasada. Por eso la nueva
     * debe empezar después de que empezó la anterior.
     */
    private function cerrarLasAnteriores(Usuario $usuario, CarbonInterface $fechaInicio): void
    {
        $fechaFin = $fechaInicio->toImmutable()->subDay();

        foreach ($usuario->asignaciones()->activas()->with('empresa')->get() as $anterior) {
            if ($anterior->fecha_inicio->greaterThan($fechaFin)) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => 'La fecha debe ser posterior al '.$anterior->fecha_inicio->format('d/m/Y').'.',
                ]);
            }

            $anterior->update(['activa' => false, 'fecha_fin' => $fechaFin->toDateString()]);
        }
    }
}
