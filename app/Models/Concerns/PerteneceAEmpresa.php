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
     * Filtra los registros que un usuario puede ver: Chazey (admin) ve todos; el consultor,
     * solo los de las empresas de su asignación activa.
     * Se usa así: Proceso::visiblePara($usuario)->get()
     */
    public function scopeVisiblePara(Builder $consulta, Usuario $usuario): Builder
    {
        return $usuario->esChazey() ? $consulta : $consulta->whereIn('id_empresa', $usuario->idsEmpresasVisibles());
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
