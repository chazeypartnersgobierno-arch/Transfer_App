<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Una hoja leída como lista de filas. Los valores llegan sin formato: una fecha de
 * Excel llega como número de serie, y una celda con fórmula llega con su resultado.
 */
class HojaLeida implements ToArray, WithCalculatedFormulas
{
    /**
     * Excel::toArray ya devuelve las filas; aquí no hace falta hacer nada con ellas.
     */
    public function array(array $array): void {}
}
