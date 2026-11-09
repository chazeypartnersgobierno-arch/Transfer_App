<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona que recibe la transferencia. Pertenece a un grupo; la empresa se obtiene
 * por grupo → bloque → empresa. Su correo es único en toda la base.
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
}
