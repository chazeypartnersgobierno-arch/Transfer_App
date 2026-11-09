<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Si un participante asistió o no a una sesión. Uno por sesión y participante.
 */
class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $primaryKey = 'id_asistencia';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_sesion', 'id_participante', 'asistio', 'comentarios'];

    protected function casts(): array
    {
        return [
            'asistio' => 'boolean',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'id_sesion', 'id_sesion');
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(Participante::class, 'id_participante', 'id_participante');
    }
}
