<?php

namespace App\Enums;

/**
 * Lista única de modalidades de una sesión (campo sesiones.modalidad).
 */
enum Modalidad: string
{
    case Presencial = 'Presencial';
    case Virtual = 'Virtual';
    case Hibrida = 'Híbrida';

    /**
     * Valores para una lista desplegable: valor => texto.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return array_column(self::cases(), 'value', 'value');
    }
}
