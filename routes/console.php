<?php

use App\Services\ArchivosDeCarga;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Borra los archivos de carga desde Excel que nadie confirmó en 24 horas.
Artisan::command('cargas:limpiar', function (ArchivosDeCarga $archivos) {
    $borrados = $archivos->limpiarViejos();
    $this->info("Archivos de carga borrados: {$borrados}.");
})->purpose('Borra los archivos de carga desde Excel sin confirmar');

Schedule::command('cargas:limpiar')->daily();
