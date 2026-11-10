<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grupo extends Model
{
    protected $table = 'grupos';

    protected $primaryKey = 'id_grupo';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_proceso', 'nombre', 'estado'];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
        ];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Proceso::class, 'id_proceso', 'id_proceso');
    }

    public function seguimientos(): HasMany
    {
        return $this->hasMany(Seguimiento::class, 'id_grupo', 'id_grupo');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(Participante::class, 'id_grupo', 'id_grupo');
    }

    /**
     * Un grupo no tiene id_empresa: su empresa es la de su proceso.
     * Chazey ve todos; los demás, solo los de su empresa.
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('proceso', fn ($q) => $q->whereIn('id_empresa', $usuario->idsEmpresasVisibles()));
    }

    /**
     * Laravel llama a este método para convertir un {grupo} de la URL en un modelo.
     * Si el grupo es de otra empresa, responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
