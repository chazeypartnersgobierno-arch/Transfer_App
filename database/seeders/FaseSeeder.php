<?php

namespace Database\Seeders;

use App\Models\Fase;
use Illuminate\Database\Seeder;

/**
 * Crea las cuatro fases fijas. Se puede ejecutar varias veces sin duplicarlas.
 * Para correrlo solo: php artisan db:seed --class=FaseSeeder
 */
class FaseSeeder extends Seeder
{
    public function run(): void
    {
        $fases = [
            ['nombre' => 'Onboarding', 'orden' => 1, 'tiene_sesiones' => true],
            ['nombre' => 'Transferencia', 'orden' => 2, 'tiene_sesiones' => true],
            ['nombre' => 'Validación', 'orden' => 3, 'tiene_sesiones' => false],
            ['nombre' => 'Estabilización', 'orden' => 4, 'tiene_sesiones' => true],
        ];

        foreach ($fases as $fase) {
            // Busca por orden, no por nombre: el nombre se puede editar en la app.
            // Así, volver a ejecutarlo no duplica una fase renombrada ni le cambia el nombre.
            Fase::firstOrCreate(['orden' => $fase['orden']], $fase);
        }
    }
}
