<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

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
}
