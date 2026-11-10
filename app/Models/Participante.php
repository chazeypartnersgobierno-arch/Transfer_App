<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona que recibe la transferencia. Pertenece a un grupo; la empresa se obtiene
 * por grupo → bloque → empresa. Su correo es único en toda la base.
 * No es un usuario de la app: no inicia sesión.
 */
class Participante extends Model
{
    protected $table = 'participantes';

    protected $primaryKey = 'id_participante';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_grupo',
        'nombre',
        'apellidos',
        'correo',
        'rol',
        'fecha_ingreso',
        'comentarios',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'estado' => 'boolean',
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'id_grupo', 'id_grupo');
    }

    public function nombreCompleto(): string
    {
        return "{$this->nombre} {$this->apellidos}";
    }

    /**
     * Filtra los participantes que un usuario puede ver: el admin, todos; el consultor,
     * los de las empresas de su asignación activa. La empresa sale del grupo → bloque.
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('grupo.bloque', fn ($q) => $q->whereIn('id_empresa', $usuario->idsEmpresasVisibles()));
    }

    /**
     * Convierte el {participante} de la URL en un modelo. Si es de otra empresa, responde 404.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
