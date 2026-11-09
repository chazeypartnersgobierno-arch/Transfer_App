<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avance de un seguimiento de la fase Validación en una fecha de corte.
 * No se borra: si se capturó mal, se corrige editándolo. id_usuario es quien hizo la última captura o corrección.
 * La tabla no tiene fecha_actualizacion: por eso UPDATED_AT = null (Laravel no intenta llenarla).
 */
class AvanceValidacion extends Model
{
    /** Valores permitidos del % esperado (la base también lo revisa con un CHECK). */
    public const PORCENTAJES_ESPERADOS = [0, 25, 50, 75, 100];

    protected $table = 'avances_validacion';

    protected $primaryKey = 'id_avance_validacion';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = null;

    protected $fillable = ['id_seguimiento', 'id_usuario', 'fecha_corte', 'porcentaje_esperado', 'porcentaje_real', 'comentarios'];

    protected function casts(): array
    {
        return [
            'fecha_corte' => 'date',
            'porcentaje_esperado' => 'integer',
            'porcentaje_real' => 'integer',
        ];
    }

    public function seguimiento(): BelongsTo
    {
        return $this->belongsTo(Seguimiento::class, 'id_seguimiento', 'id_seguimiento');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    /**
     * Opciones para la lista desplegable del % esperado: [0 => '0 %', 25 => '25 %', ...].
     */
    public static function opcionesEsperado(): array
    {
        return collect(self::PORCENTAJES_ESPERADOS)->mapWithKeys(fn (int $p) => [$p => "{$p} %"])->all();
    }

    /**
     * Chazey ve todos; los demás, solo los de su empresa (la de la actividad del seguimiento).
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        // La empresa sale del propio registro (seguimiento.actividad.bloque.empresa), nunca de la asignación del usuario:
        // si a un consultor lo cambian de empresa, lo que capturó se queda en la empresa original.
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('seguimiento.actividad.bloque', fn ($q) => $q->whereIn('id_empresa', $usuario->idsEmpresasVisibles()));
    }

    /**
     * Un avance de otra empresa responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
