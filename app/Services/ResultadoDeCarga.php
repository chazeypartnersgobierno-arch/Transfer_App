<?php

namespace App\Services;

/**
 * Lo que encontró la revisión de un archivo de carga: cada fila de cada hoja con su
 * estado (nueva, duplicado o error) y los errores que no son de una fila en particular.
 */
class ResultadoDeCarga
{
    public const NUEVA = 'nueva';

    public const DUPLICADO = 'duplicado';

    public const ERROR = 'error';

    /** @var list<string> Errores del archivo o de la hoja completa (falta una hoja, una columna...). */
    public array $erroresGenerales = [];

    /**
     * @var array<string, list<array{fila: int, estado: string, mostrar: array<string, string>, errores: list<string>, aviso: ?string, guardar: ?array}>>
     *            Por hoja. "mostrar" son los datos para la vista previa; "guardar", lo que se insertará.
     */
    public array $filas = ['Actividades' => [], 'Participantes' => []];

    /** Seguimientos que se generarán para las actividades nuevas. */
    public int $seguimientosPorGenerar = 0;

    /**
     * Un duplicado no es error: solo no se inserta. $duplicado es el aviso que explica por qué.
     */
    public function agregarFila(string $hoja, int $fila, array $mostrar, array $errores, ?array $guardar, ?string $duplicado = null): void
    {
        $estado = match (true) {
            $errores !== [] => self::ERROR,
            $duplicado !== null => self::DUPLICADO,
            default => self::NUEVA,
        };

        $this->filas[$hoja][] = [
            'fila' => $fila,
            'estado' => $estado,
            'mostrar' => $mostrar,
            'errores' => $errores,
            'aviso' => $errores === [] ? $duplicado : null,
            'guardar' => $estado === self::NUEVA ? $guardar : null,
        ];
    }

    /**
     * Cuántas filas hay de cada estado en una hoja.
     *
     * @return array{nueva: int, duplicado: int, error: int}
     */
    public function totales(string $hoja): array
    {
        $totales = [self::NUEVA => 0, self::DUPLICADO => 0, self::ERROR => 0];

        foreach ($this->filas[$hoja] as $fila) {
            $totales[$fila['estado']]++;
        }

        return $totales;
    }

    /**
     * Datos listos para insertar, en el orden del archivo.
     *
     * @return list<array>
     */
    public function porGuardar(string $hoja): array
    {
        return array_values(array_filter(array_column($this->filas[$hoja], 'guardar')));
    }

    public function hayErrores(): bool
    {
        return $this->erroresGenerales !== []
            || $this->totales('Actividades')[self::ERROR] > 0
            || $this->totales('Participantes')[self::ERROR] > 0;
    }

    /**
     * Se puede confirmar si no hay ningún error y hay al menos un registro nuevo.
     */
    public function sePuedeConfirmar(): bool
    {
        return ! $this->hayErrores()
            && ($this->porGuardar('Actividades') !== [] || $this->porGuardar('Participantes') !== []);
    }
}
