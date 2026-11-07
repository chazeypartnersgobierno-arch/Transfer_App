<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para los modelos que tienen id_empresa. Un "trait" es código que varias
 * clases comparten: así la regla de quién ve qué vive en un solo lugar.
 */
trait PerteneceAEmpresa
{
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa', 'id_empresa');
    }

    /**
     * Filtra los registros que un usuario puede ver: Chazey ve todos; los demás, solo los de su empresa.
     * Se usa así: Bloque::visiblePara($usuario)->get()
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey() ? $consulta : $consulta->where('id_empresa', $usuario->id_empresa);
    }

    /**
     * Laravel llama a este método para convertir un {id} de la URL en un modelo.
     * Si el registro es de otra empresa, responde 404, como si no existiera.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::visiblePara(auth()->user())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
