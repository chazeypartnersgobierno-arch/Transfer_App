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
        'id_proceso',
        'tipo',
        'macroproceso',
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
     * Toda actividad tiene proceso: solo genera seguimientos para los grupos de ese proceso.
     */
    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Proceso::class, 'id_proceso', 'id_proceso');
    }

    public function seguimientos(): HasMany
    {
        return $this->hasMany(Seguimiento::class, 'id_actividad', 'id_actividad');
    }
}
