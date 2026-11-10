<?php

namespace App\Enums;

/**
 * Lista única de estados de una solicitud de acceso (campo accesos_solicitados.estado).
 * Los cambios entre estados son libres, sin orden obligatorio.
 */
enum EstadoAcceso: string
{
    case Solicitado = 'Solicitado';
    case EnTramite = 'En trámite';
    case Otorgado = 'Otorgado';
    case Rechazado = 'Rechazado';

    /**
     * Valores para una lista desplegable: valor => texto.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }

    /**
     * "En curso" = cualquier estado salvo Rechazado. Solo puede haber una solicitud en
     * curso por participante y aplicativo; tras un rechazo se puede volver a pedir.
     */
    public function estaEnCurso(): bool
    {
        return $this !== self::Rechazado;
    }
}
