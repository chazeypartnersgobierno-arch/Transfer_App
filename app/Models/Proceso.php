<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proceso extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'procesos';

    protected $primaryKey = 'id_proceso';

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
        return $this->hasMany(Actividad::class, 'id_proceso', 'id_proceso');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class, 'id_proceso', 'id_proceso');
    }
}
