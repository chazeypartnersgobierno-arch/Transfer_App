<?php

namespace App\Models;

use App\Enums\Modalidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesión de un seguimiento (solo en fases con tiene_sesiones = 1).
 * No se borra: si se capturó mal, se corrige editándola.
 */
class Sesion extends Model
{
    protected $table = 'sesiones';

    protected $primaryKey = 'id_sesion';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_seguimiento', 'fecha', 'tema', 'modalidad', 'comentarios'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'modalidad' => Modalidad::class,
        ];
    }

    public function seguimiento(): BelongsTo
    {
        return $this->belongsTo(Seguimiento::class, 'id_seguimiento', 'id_seguimiento');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'id_sesion', 'id_sesion');
    }

    /**
     * Chazey ve todas; los demás, solo las de su empresa (la de la actividad del seguimiento).
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('seguimiento.actividad', fn ($q) => $q->where('id_empresa', $usuario->id_empresa));
    }

    /**
     * Una sesión de otra empresa responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
