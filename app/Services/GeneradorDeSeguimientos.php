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
 * - Qué actividades: las de su bloque (toda actividad tiene bloque).
 * - Cuántos seguimientos: uno por cada fase del tipo de la actividad
 *   (Onboarding: 1; Transición: 3, uno por Transferencia, Estabilización y Validación).
 *
 * Solo genera combinaciones con grupo y actividad activos, y nunca toca lo que ya existe.
 * Los seguimientos nuevos copian el equipo de su bloque (Seguimiento::CAMPOS_DEL_BLOQUE).
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
            ->where('id_bloque', $grupo->id_bloque)
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
            ->where('id_bloque', $actividad->id_bloque)
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
     * Equipo actual de un bloque, tomado de su seguimiento más reciente.
     * Si el bloque todavía no tiene seguimientos, el equipo queda vacío.
     *
     * @return array<string, ?string>
     */
    public function equipoDelBloque(int $idBloque): array
    {
        $campos = array_keys(Seguimiento::CAMPOS_DEL_BLOQUE);
        $ultimo = Seguimiento::delBloque($idBloque)->latest('id_seguimiento')->first($campos);

        return $ultimo?->only($campos) ?? array_fill_keys($campos, null);
    }

    /**
     * Guarda las combinaciones. Todo o nada: si una falla, no queda ninguna.
     */
    private function crear(Collection $combinaciones): int
    {
        // Bloque de cada grupo, para copiar el equipo que ya tiene ese bloque.
        $bloquePorGrupo = Grupo::whereIn('id_grupo', $combinaciones->pluck('id_grupo')->unique())->pluck('id_bloque', 'id_grupo');
        $equipos = $bloquePorGrupo->unique()->mapWithKeys(fn ($idBloque) => [$idBloque => $this->equipoDelBloque($idBloque)]);

        return DB::transaction(function () use ($combinaciones, $bloquePorGrupo, $equipos) {
            foreach ($combinaciones as $combinacion) {
                Seguimiento::create([...$combinacion, ...$equipos[$bloquePorGrupo[$combinacion['id_grupo']]]]);
            }

            return $combinaciones->count();
        });
    }
}
