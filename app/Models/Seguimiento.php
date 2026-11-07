<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seguimiento extends Model
{
    protected $table = 'seguimientos';

    protected $primaryKey = 'id_seguimiento';

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'id_actividad',
        'id_grupo',
        'fecha_inicio',
        'fecha_fin',
        'estado_actividad',
        'documentacion',
        'comentarios',
        'responsable',
        'experto',
        'documentador',
        'lider_transferencia',
        'lider_transferencia_gbs',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'estado' => 'boolean',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'id_actividad', 'id_actividad');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'id_grupo', 'id_grupo');
    }

    /**
     * Solo los seguimientos que cuentan para el trabajo diario y los porcentajes de avance:
     * su grupo y su actividad deben estar activos. Los demás siguen guardados y se consultan con un filtro.
     * Se usa así: Seguimiento::enTrabajo()->get()
     */
    public function scopeEnTrabajo(Builder $consulta): Builder
    {
        return $consulta
            ->whereHas('grupo', fn ($q) => $q->where('estado', true))
            ->whereHas('actividad', fn ($q) => $q->where('estado', true));
    }

    /**
     * Texto con la cantidad, para los mensajes: "1 seguimiento" o "3 seguimientos".
     */
    public static function textoCantidad(int $cantidad): string
    {
        return $cantidad === 1 ? '1 seguimiento' : "{$cantidad} seguimientos";
    }

    /**
     * Aviso previo: "Se generará 1 seguimiento" o "Se generarán 3 seguimientos".
     */
    public static function seGeneraran(int $cantidad): string
    {
        return ($cantidad === 1 ? 'Se generará ' : 'Se generarán ').self::textoCantidad($cantidad);
    }

    /**
     * Resultado: "Se generó 1 seguimiento" o "Se generaron 3 seguimientos".
     */
    public static function seGeneraron(int $cantidad): string
    {
        return ($cantidad === 1 ? 'Se generó ' : 'Se generaron ').self::textoCantidad($cantidad);
    }

    /**
     * Aviso antes de dar de baja un grupo o una actividad.
     */
    public static function avisoDeBaja(int $cantidad): string
    {
        return match ($cantidad) {
            0 => 'No tiene seguimientos.',
            1 => 'Tiene 1 seguimiento: queda intacto, pero no aparecerá en las listas de trabajo ni contará en el avance mientras esté de baja.',
            default => "Tiene {$cantidad} seguimientos: quedan intactos, pero no aparecerán en las listas de trabajo ni contarán en el avance mientras esté de baja.",
        };
    }
}
