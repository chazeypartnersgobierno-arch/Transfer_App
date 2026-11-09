<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Limpieza y comparación de textos que llegan de un archivo.
 */
class Texto
{
    /**
     * Quita espacios de los extremos y deja un solo espacio entre palabras.
     * Incluye el espacio "duro" (no separable) que a veces trae Excel.
     */
    public static function limpiar(mixed $valor): string
    {
        if ($valor === null || is_array($valor)) {
            return '';
        }

        // Un número que Excel guardó como 1.0 se lee como "1".
        $texto = (string) $valor;

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $texto) ?? $texto);
    }

    /**
     * Llave para comparar dos textos sin importar mayúsculas, acentos ni espacios de más.
     * Es la misma idea que usa MySQL en esta base (utf8mb4_0900_ai_ci):
     * "Tesorería", "TESORERIA" y " tesoreria " se consideran iguales.
     */
    public static function llave(mixed $valor): string
    {
        return mb_strtolower(Str::ascii(self::limpiar($valor)));
    }
}
