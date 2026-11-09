<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Una hoja de la plantilla de carga. Cada hoja recibe sus filas y, si las necesita,
 * listas desplegables y anchos de columna. No se da formato a celdas vacías:
 * eso crearía filas de más que la carga tendría que saltar.
 *
 * Extiende StringValueBinder para que todo se escriba como texto: así un nombre del
 * catálogo que empiece con "=" nunca se interpreta como una fórmula de Excel.
 */
class HojaDePlantilla extends StringValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    /** Hasta qué fila llegan las listas desplegables y el formato de fecha. */
    public const ULTIMA_FILA = 2000;

    /**
     * @param  list<list<string>>  $filas  La primera fila son los encabezados.
     * @param  array<string, string>  $listas  Columna => rango de la hoja Listas, por ejemplo 'E' => '$A$2:$A$9'.
     * @param  array<string, int>  $anchos  Columna => ancho.
     */
    public function __construct(
        private string $titulo,
        private array $filas,
        private array $listas = [],
        private array $anchos = [],
        private bool $oculta = false,
        private bool $conEncabezado = true,
    ) {}

    public function title(): string
    {
        return $this->titulo;
    }

    public function array(): array
    {
        return $this->filas;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento) {
                $hoja = $evento->sheet->getDelegate();

                foreach ($this->anchos as $columna => $ancho) {
                    $hoja->getColumnDimension($columna)->setWidth($ancho);
                }

                if ($this->conEncabezado) {
                    $this->darEstiloAlEncabezado($hoja);
                }

                foreach ($this->listas as $columna => $rango) {
                    $this->agregarLista($hoja, $columna, $rango);
                }

                if ($this->oculta) {
                    $hoja->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
                }
            },
        ];
    }

    /**
     * Encabezado con el color principal de la app y fijo al desplazarse.
     */
    private function darEstiloAlEncabezado(Worksheet $hoja): void
    {
        $ultima = $hoja->getHighestColumn();

        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B396A']],
        ]);
        $hoja->freezePane('A2');
    }

    /**
     * Lista desplegable en toda la columna, con los valores de un rango de la hoja Listas.
     */
    private function agregarLista(Worksheet $hoja, string $columna, string $rango): void
    {
        // Se asigna al rango sin tocar las celdas: así la hoja no tiene filas vacías de más.
        $validacion = new DataValidation;
        $validacion->setType(DataValidation::TYPE_LIST);
        $validacion->setAllowBlank(true);
        $validacion->setShowDropDown(true);
        $validacion->setShowErrorMessage(true);
        $validacion->setErrorStyle(DataValidation::STYLE_STOP);
        $validacion->setErrorTitle('Valor no válido');
        $validacion->setError('Elige un valor de la lista.');
        $validacion->setFormula1("'Listas'!{$rango}");

        $hoja->setDataValidation("{$columna}2:{$columna}".self::ULTIMA_FILA, $validacion);
    }
}
