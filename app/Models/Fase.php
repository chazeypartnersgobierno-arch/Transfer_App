<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fases del proceso, las mismas para todas las empresas. Las crea FaseSeeder.
 * En la app solo se puede cambiar el nombre, así que la lógica debe usar
 * orden y tiene_sesiones, nunca el nombre.
 */
class Fase extends Model
{
    protected $table = 'fases';

    protected $primaryKey = 'id_fase';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['nombre', 'orden', 'tiene_sesiones'];

    protected function casts(): array
    {
        return [
            'tiene_sesiones' => 'boolean',
        ];
    }
}
