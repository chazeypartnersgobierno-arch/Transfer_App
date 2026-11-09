<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Periodo en que un consultor trabaja con una empresa. Solo ve los datos de las
 * empresas de sus asignaciones activas. No se borra: al terminar se cierra
 * (activa = 0 y fecha_fin). Para crearlas o cerrarlas usa App\Services\AsignadorDeEmpresas.
 */
class AsignacionEmpresa extends Model
{
    protected $table = 'usuario_empresa';

    protected $primaryKey = 'id_usuario_empresa';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_usuario', 'id_empresa', 'fecha_inicio', 'fecha_fin', 'activa'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'activa' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa', 'id_empresa');
    }

    /**
     * Solo las asignaciones vigentes. Se usa así: AsignacionEmpresa::activas()->get()
     */
    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('activa', true);
    }
}
