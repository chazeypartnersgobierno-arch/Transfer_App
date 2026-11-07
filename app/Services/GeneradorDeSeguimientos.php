<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\Grupo;
use App\Models\Seguimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar con la regla de qué seguimientos debe tener cada grupo:
 * las actividades de Onboarding de su empresa (id_bloque NULL) y las de su bloque.
 * Solo genera combinaciones con grupo y actividad activos, y nunca toca lo que ya existe.
 */
class GeneradorDeSeguimientos
{
    /**
     * Actividades activas que le corresponden a un grupo y todavía no tiene.
     */
    public function actividadesFaltantes(Grupo $grupo): Builder
    {
        return Actividad::query()
            ->where('estado', true)
            ->where('id_empresa', $grupo->bloque->id_empresa)
            ->where(fn ($q) => $q->whereNull('id_bloque')->orWhere('id_bloque', $grupo->id_bloque))
            ->whereDoesntHave('seguimientos', fn ($q) => $q->where('id_grupo', $grupo->id_grupo));
    }

    /**
     * Grupos activos a los que les corresponde una actividad y todavía no la tienen.
     * También sirve para una actividad que aún no se guarda, para avisar antes de crearla.
     */
    public function gruposFaltantes(Actividad $actividad): Builder
    {
        return Grupo::query()
            ->where('estado', true)
            ->when(
                $actividad->id_bloque === null,
                fn ($q) => $q->whereHas('bloque', fn ($b) => $b->where('id_empresa', $actividad->id_empresa)),
                fn ($q) => $q->where('id_bloque', $actividad->id_bloque)
            )
            ->when(
                $actividad->exists,
                fn ($q) => $q->whereDoesntHave('seguimientos', fn ($s) => $s->where('id_actividad', $actividad->id_actividad))
            );
    }

    /**
     * Crea los seguimientos que le faltan a un grupo y devuelve cuántos creó.
     */
    public function generarParaGrupo(Grupo $grupo): int
    {
        if (! $grupo->estado) {
            return 0;
        }

        return DB::transaction(function () use ($grupo) {
            $ids = $this->actividadesFaltantes($grupo)->pluck('id_actividad');

            foreach ($ids as $idActividad) {
                Seguimiento::create(['id_actividad' => $idActividad, 'id_grupo' => $grupo->id_grupo]);
            }

            return $ids->count();
        });
    }

    /**
     * Crea los seguimientos que le faltan a una actividad y devuelve cuántos creó.
     */
    public function generarParaActividad(Actividad $actividad): int
    {
        if (! $actividad->estado) {
            return 0;
        }

        return DB::transaction(function () use ($actividad) {
            $ids = $this->gruposFaltantes($actividad)->pluck('id_grupo');

            foreach ($ids as $idGrupo) {
                Seguimiento::create(['id_actividad' => $actividad->id_actividad, 'id_grupo' => $idGrupo]);
            }

            return $ids->count();
        });
    }
}
