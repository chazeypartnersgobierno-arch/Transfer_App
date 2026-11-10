<?php

namespace App\Models;

use App\Enums\EstadoAcceso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de acceso de un participante a un aplicativo de su misma empresa.
 * No se borra ni tiene baja lógica: se corrige editándola. Aquí "estado" es el
 * estado del acceso (App\Enums\EstadoAcceso), no el BOOLEAN de baja.
 */
class AccesoSolicitado extends Model
{
    protected $table = 'accesos_solicitados';

    protected $primaryKey = 'id_acceso_solicitado';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_participante',
        'id_aplicativo',
        'fecha_solicitud',
        'fecha_otorgado',
        'estado',
        'comentarios',
    ];

    protected function casts(): array
    {
        return [
            'fecha_solicitud' => 'date',
            'fecha_otorgado' => 'date',
            'estado' => EstadoAcceso::class,
        ];
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(Participante::class, 'id_participante', 'id_participante');
    }

    public function aplicativo(): BelongsTo
    {
        return $this->belongsTo(Aplicativo::class, 'id_aplicativo', 'id_aplicativo');
    }

    /**
     * Solicitudes en curso: todas menos las rechazadas.
     */
    public function scopeEnCurso(Builder $consulta): Builder
    {
        return $consulta->where('estado', '!=', EstadoAcceso::Rechazado->value);
    }

    /**
     * Filtra lo que un usuario puede ver: el admin, todo; el consultor, lo de las empresas de
     * su asignación activa. La empresa sale del participante → grupo → proceso.
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey()
            ? $consulta
            : $consulta->whereHas('participante.grupo.proceso', fn ($q) => $q->whereIn('id_empresa', $usuario->idsEmpresasVisibles()));
    }

    /**
     * Convierte el {acceso} de la URL en un modelo. Si es de otra empresa, responde 404.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Una solicitud de un participante o aplicativo dados de baja solo se consulta.
     */
    public function sePuedeEditar(): bool
    {
        return $this->participante->estado && $this->aplicativo->estado;
    }
}
