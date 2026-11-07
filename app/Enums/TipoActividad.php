<?php

namespace App\Enums;

/**
 * Lista única de tipos de actividad (campo actividades.tipo).
 * El tipo decide por qué fases pasa la actividad y, por lo tanto, cuántos
 * seguimientos genera en cada grupo.
 */
enum TipoActividad: string
{
    case Onboarding = 'Onboarding';
    case Transicion = 'Transición';

    /**
     * Orden de las fases por las que pasa. Se usa el orden porque el nombre de una fase se puede editar.
     *
     * @return list<int>
     */
    public function ordenesDeFase(): array
    {
        return match ($this) {
            self::Onboarding => [1],
            self::Transicion => [2, 3, 4],
        };
    }

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
