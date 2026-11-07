<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

class Aplicativo extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'aplicativos';

    protected $primaryKey = 'id_aplicativo';

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
