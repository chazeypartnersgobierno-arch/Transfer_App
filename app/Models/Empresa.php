<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    protected $table = 'empresas';

    protected $primaryKey = 'id_empresa';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = ['nombre', 'sigla', 'estado'];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'id_empresa', 'id_empresa');
    }

    /**
     * Filtra las empresas que un usuario puede ver: Chazey ve todas; los demás, solo la suya.
     * Se usa así: Empresa::visiblePara($usuario)->get()
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey() ? $consulta : $consulta->whereKey($usuario->id_empresa);
    }

    /**
     * Laravel llama a este método para convertir el {empresa} de una URL en un modelo.
     * Si la empresa no es visible para el usuario, responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
