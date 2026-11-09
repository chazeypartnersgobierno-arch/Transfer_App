<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivos subidos que esperan confirmación. Se guardan en storage/app/private/cargas
 * (fuera de public, nadie los descarga) y se borran al confirmar, al cancelar, al subir
 * otro o, si nadie los confirmó, después de HORAS horas.
 */
class ArchivosDeCarga
{
    public const CARPETA = 'cargas';

    public const HORAS = 24;

    /**
     * Guarda el archivo con un nombre al azar y devuelve ese nombre.
     */
    public function guardar(UploadedFile $archivo): string
    {
        $nombre = Str::uuid().'.xlsx';

        Storage::disk('local')->putFileAs(self::CARPETA, $archivo, $nombre);

        return $nombre;
    }

    /**
     * Ruta completa del archivo, o null si ya no existe.
     */
    public function ruta(?string $nombre): ?string
    {
        if (! $this->nombreValido($nombre) || ! Storage::disk('local')->exists($this->relativa($nombre))) {
            return null;
        }

        return Storage::disk('local')->path($this->relativa($nombre));
    }

    public function borrar(?string $nombre): void
    {
        if ($this->nombreValido($nombre)) {
            Storage::disk('local')->delete($this->relativa($nombre));
        }
    }

    /**
     * Borra los archivos que nadie confirmó en HORAS horas. Devuelve cuántos borró.
     */
    public function limpiarViejos(): int
    {
        $disco = Storage::disk('local');
        $limite = now()->subHours(self::HORAS)->getTimestamp();
        $borrados = 0;

        foreach ($disco->files(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * Solo nombres generados aquí: así nadie puede pedir otra ruta (por ejemplo "../.env").
     */
    private function nombreValido(?string $nombre): bool
    {
        return $nombre !== null && preg_match('/^[0-9a-f-]{36}\.xlsx$/', $nombre) === 1;
    }

    private function relativa(string $nombre): string
    {
        return self::CARPETA.'/'.$nombre;
    }
}
