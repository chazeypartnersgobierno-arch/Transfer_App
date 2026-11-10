<?php

namespace App\Services;

use App\Enums\EstadoActividad;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use Illuminate\Support\Collection;

/**
 * Avance secuencial de las fases de un grupo. Todo se calcula; no se guarda nada.
 *
 * - Las fases van en el orden de fases.orden (el único lugar donde se define el orden).
 * - La primera fase siempre está habilitada. Las demás, solo cuando todas las anteriores están completas.
 * - Una fase está completa cuando todos sus seguimientos en trabajo del grupo están en Completada.
 *   Una fase sin seguimientos cuenta como completa (no tiene nada pendiente).
 * - Cada grupo avanza por su cuenta: no depende de los otros grupos del proceso.
 *   (Un grupo pertenece a un solo proceso, por eso basta con el grupo).
 *
 * Uso: $avance = app(AvanceDeFases::class); $avance->faseHabilitada($fase, $grupo).
 * Guarda los cálculos mientras vive el objeto: créalo de nuevo después de cambiar estados.
 */
class AvanceDeFases
{
    /** @var Collection<int, Fase>|null */
    private ?Collection $fases = null;

    /** Por grupo: id_fase => ['terminadas' => int, 'total' => int]. */
    private array $conteos = [];

    /**
     * Las fases en su orden.
     *
     * @return Collection<int, Fase>
     */
    public function fases(): Collection
    {
        return $this->fases ??= Fase::orderBy('orden')->get();
    }

    /**
     * Cuántos seguimientos de la fase tiene el grupo y cuántos están en Completada.
     *
     * @return array{terminadas: int, total: int}
     */
    public function avance(Fase $fase, Grupo $grupo): array
    {
        return $this->conteosDelGrupo($grupo)[$fase->id_fase] ?? ['terminadas' => 0, 'total' => 0];
    }

    public function faseCompleta(Fase $fase, Grupo $grupo): bool
    {
        $avance = $this->avance($fase, $grupo);

        return $avance['terminadas'] === $avance['total'];
    }

    /**
     * Habilitada si todas las fases anteriores están completas (la primera, siempre).
     */
    public function faseHabilitada(Fase $fase, Grupo $grupo): bool
    {
        return $this->faseQueBloquea($fase, $grupo) === null;
    }

    /**
     * La primera fase no completa del grupo, o null si ya completó todas.
     */
    public function faseActual(Grupo $grupo): ?Fase
    {
        return $this->fases()->first(fn (Fase $f) => ! $this->faseCompleta($f, $grupo));
    }

    /**
     * La fase anterior que todavía no concluye y por eso bloquea a $fase, o null si $fase está habilitada.
     * Normalmente es la inmediata anterior; si se reabrió una más atrás, es esa.
     */
    public function faseQueBloquea(Fase $fase, Grupo $grupo): ?Fase
    {
        return $this->fases()
            ->filter(fn (Fase $f) => $f->orden < $fase->orden)
            ->first(fn (Fase $f) => ! $this->faseCompleta($f, $grupo));
    }

    /**
     * Mensaje para mostrar o para rechazar un guardado, o null si la fase está habilitada.
     */
    public function mensajeDeBloqueo(Fase $fase, Grupo $grupo): ?string
    {
        $anterior = $this->faseQueBloquea($fase, $grupo);

        if ($anterior === null) {
            return null;
        }

        ['terminadas' => $terminadas, 'total' => $total] = $this->avance($anterior, $grupo);

        return "Esta fase se habilitará cuando concluya {$anterior->nombre} del proceso {$grupo->proceso->nombre} – {$grupo->nombre} "
            ."({$terminadas} de {$total} ".($total === 1 ? 'actividad terminada' : 'actividades terminadas').').';
    }

    /**
     * Mensaje de bloqueo del seguimiento (de su fase y su grupo), o null si se puede trabajar.
     */
    public function bloqueoDe(Seguimiento $seguimiento): ?string
    {
        return $this->mensajeDeBloqueo($seguimiento->fase, $seguimiento->grupo);
    }

    /**
     * Una sola consulta por grupo: trae la fase y el estado de sus seguimientos en trabajo y los cuenta.
     */
    private function conteosDelGrupo(Grupo $grupo): array
    {
        return $this->conteos[$grupo->id_grupo] ??= Seguimiento::enTrabajo()
            ->where('id_grupo', $grupo->id_grupo)
            ->get(['id_fase', 'estado_actividad'])
            ->groupBy('id_fase')
            ->map(fn (Collection $deLaFase) => [
                'terminadas' => $deLaFase->filter(fn (Seguimiento $s) => $s->estado_actividad === EstadoActividad::Completada)->count(),
                'total' => $deLaFase->count(),
            ])
            ->all();
    }
}
