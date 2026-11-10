<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar con la regla de qué seguimientos debe tener cada grupo.
 *
 * - Qué actividades: las de su proceso (toda actividad tiene proceso).
 * - Cuántos seguimientos: uno por cada fase del tipo de la actividad
 *   (Onboarding: 1; Transición: 3, uno por Transferencia, Validación y Estabilización).
 *
 * Solo genera combinaciones con grupo y actividad activos, y nunca toca lo que ya existe.
 * Los seguimientos nuevos copian el equipo de su proceso (Seguimiento::CAMPOS_DEL_PROCESO).
 */
class GeneradorDeSeguimientos
{
    /**
     * Seguimientos que le faltan a un grupo, sin guardarlos. Sirve para avisar antes de reactivarlo.
     *
     * @return Collection<int, array{id_actividad: int, id_grupo: int, id_fase: int}>
     */
    public function faltantesDeGrupo(Grupo $grupo): Collection
    {
        $actividades = Actividad::query()
            ->where('estado', true)
            ->where('id_proceso', $grupo->id_proceso)
            ->orderBy('id_actividad')
            ->get();

        return $this->faltantes($actividades, collect([$grupo]));
    }

    /**
     * Seguimientos que le faltan a una actividad, sin guardarlos.
     * También sirve para una actividad que aún no se guarda, para avisar antes de crearla.
     *
     * @return Collection<int, array{id_actividad: ?int, id_grupo: int, id_fase: int}>
     */
    public function faltantesDeActividad(Actividad $actividad): Collection
    {
        $grupos = Grupo::query()
            ->where('estado', true)
            ->where('id_proceso', $actividad->id_proceso)
            ->orderBy('id_grupo')
            ->get();

        return $this->faltantes(collect([$actividad]), $grupos);
    }

    /**
     * Crea los seguimientos que le faltan a un grupo y devuelve cuántos creó.
     */
    public function generarParaGrupo(Grupo $grupo): int
    {
        return $grupo->estado ? $this->crear($this->faltantesDeGrupo($grupo)) : 0;
    }

    /**
     * Crea los seguimientos que le faltan a una actividad y devuelve cuántos creó.
     */
    public function generarParaActividad(Actividad $actividad): int
    {
        return $actividad->estado ? $this->crear($this->faltantesDeActividad($actividad)) : 0;
    }

    /**
     * Cruza actividades, grupos y fases, y descarta las combinaciones que ya existen.
     */
    private function faltantes(Collection $actividades, Collection $grupos): Collection
    {
        // Lo que ya existe, para compararlo sin hacer una consulta por combinación.
        $existentes = Seguimiento::query()
            ->whereIn('id_actividad', $actividades->pluck('id_actividad')->filter())
            ->whereIn('id_grupo', $grupos->pluck('id_grupo'))
            ->get(['id_actividad', 'id_grupo', 'id_fase'])
            ->mapWithKeys(fn (Seguimiento $s) => ["{$s->id_actividad}-{$s->id_grupo}-{$s->id_fase}" => true]);

        $fasePorOrden = Fase::pluck('id_fase', 'orden');
        $faltantes = collect();

        foreach ($actividades as $actividad) {
            foreach ($grupos as $grupo) {
                foreach ($actividad->tipo->ordenesDeFase() as $orden) {
                    $idFase = $fasePorOrden[$orden];

                    if (! $existentes->has("{$actividad->id_actividad}-{$grupo->id_grupo}-{$idFase}")) {
                        $faltantes->push([
                            'id_actividad' => $actividad->id_actividad,
                            'id_grupo' => $grupo->id_grupo,
                            'id_fase' => $idFase,
                        ]);
                    }
                }
            }
        }

        return $faltantes;
    }

    /**
     * Equipo actual de un proceso, tomado de su seguimiento más reciente.
     * Si el proceso todavía no tiene seguimientos, el equipo queda vacío.
     *
     * @return array<string, ?string>
     */
    public function equipoDelProceso(int $idProceso): array
    {
        $campos = array_keys(Seguimiento::CAMPOS_DEL_PROCESO);
        $ultimo = Seguimiento::delProceso($idProceso)->latest('id_seguimiento')->first($campos);

        return $ultimo?->only($campos) ?? array_fill_keys($campos, null);
    }

    /**
     * Guarda las combinaciones. Todo o nada: si una falla, no queda ninguna.
     */
    private function crear(Collection $combinaciones): int
    {
        // Proceso de cada grupo, para copiar el equipo que ya tiene ese proceso.
        $procesoPorGrupo = Grupo::whereIn('id_grupo', $combinaciones->pluck('id_grupo')->unique())->pluck('id_proceso', 'id_grupo');
        $equipos = $procesoPorGrupo->unique()->mapWithKeys(fn ($idProceso) => [$idProceso => $this->equipoDelProceso($idProceso)]);

        return DB::transaction(function () use ($combinaciones, $procesoPorGrupo, $equipos) {
            foreach ($combinaciones as $combinacion) {
                Seguimiento::create([...$combinacion, ...$equipos[$procesoPorGrupo[$combinacion['id_grupo']]]]);
            }

            return $combinaciones->count();
        });
    }
}
