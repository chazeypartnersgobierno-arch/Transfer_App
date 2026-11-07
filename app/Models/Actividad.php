<?php

namespace App\Models;

use App\Enums\TipoActividad;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Actividad extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'actividades';

    protected $primaryKey = 'id_actividad';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_empresa',
        'id_bloque',
        'tipo',
        'macroproceso',
        'proceso',
        'subproceso',
        'nombre',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoActividad::class,
            'estado' => 'boolean',
        ];
    }

    /**
     * Sin bloque (NULL), la actividad aplica a todos los grupos de la empresa.
     */
    public function bloque(): BelongsTo
    {
        return $this->belongsTo(Bloque::class, 'id_bloque', 'id_bloque');
    }

    public function seguimientos(): HasMany
    {
        return $this->hasMany(Seguimiento::class, 'id_actividad', 'id_actividad');
    }

    /**
     * Macroproceso, proceso y subproceso en una sola línea, omitiendo los vacíos.
     */
    public function rutaDeProceso(): string
    {
        return collect([$this->macroproceso, $this->proceso, $this->subproceso])->filter()->implode(' › ');
    }
}
