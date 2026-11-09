<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lee las hojas Actividades y Participantes del archivo de carga, tal como vienen.
 * Solo lee: la revisión de cada fila la hace App\Services\CargaDesdeExcel.
 *
 * Uso: Excel::toArray(new LectorDeCarga, $ruta) devuelve
 * ['Actividades' => [filas...], 'Participantes' => [filas...]]; una hoja que no existe no aparece.
 */
class LectorDeCarga implements Import, WithMultipleSheets, SkipsUnknownSheets
{
    public const HOJAS = ['Actividades', 'Participantes'];

    public function sheets(): array
    {
        // Las hojas se buscan por nombre; Instrucciones y Listas se ignoran.
        return array_fill_keys(self::HOJAS, new HojaLeida);
    }

    /**
     * Si falta una hoja no se detiene la lectura: CargaDesdeExcel avisa cuál falta.
     */
    public function onUnknownSheet($sheetName): void {}
}
