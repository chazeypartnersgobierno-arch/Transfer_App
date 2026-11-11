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

    /**
     * La fase con ese id; si no existe (o no se pidió ninguna), la primera según su orden.
     * La usa la lista de seguimientos, donde cada fase es su propia pantalla.
     */
    public static function elegidaOPrimera(?int $idFase): ?self
    {
        return ($idFase !== null ? static::find($idFase) : null) ?? static::orderBy('orden')->first();
    }

    protected function casts(): array
    {
        return [
            'tiene_sesiones' => 'boolean',
        ];
    }
}
