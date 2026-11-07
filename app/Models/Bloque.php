<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bloque extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'bloques';

    protected $primaryKey = 'id_bloque';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['id_empresa', 'nombre', 'estado'];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
        ];
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class, 'id_bloque', 'id_bloque');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class, 'id_bloque', 'id_bloque');
    }
}
