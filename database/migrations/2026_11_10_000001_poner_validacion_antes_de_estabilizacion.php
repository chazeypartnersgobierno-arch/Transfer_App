<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nuevo orden de las fases: Onboarding 1, Transferencia 2, Validación 3, Estabilización 4.
     * Solo cambia datos (fases.orden), no la estructura. El orden vive solo en esta columna:
     * para cambiarlo otra vez, se hace otra migración como esta.
     *
     * Las fases se reconocen por tiene_sesiones, no por el nombre (se puede editar):
     * Validación es la única sin sesiones.
     */
    public function up(): void
    {
        $this->intercambiar(validacionEn: 4, pasaA: 3);
    }

    public function down(): void
    {
        $this->intercambiar(validacionEn: 3, pasaA: 4);
    }

    /**
     * Si Validación está en el orden $validacionEn, la pasa a $pasaA y la fase que estaba en $pasaA
     * toma su lugar. Si no está ahí (por ejemplo, en una base nueva sin fases), no hace nada.
     */
    private function intercambiar(int $validacionEn, int $pasaA): void
    {
        $validacion = DB::table('fases')->where('orden', $validacionEn)->where('tiene_sesiones', false)->value('id_fase');
        $otra = DB::table('fases')->where('orden', $pasaA)->where('tiene_sesiones', true)->value('id_fase');

        if ($validacion === null || $otra === null) {
            return;
        }

        DB::transaction(function () use ($validacion, $otra, $validacionEn, $pasaA) {
            DB::table('fases')->where('id_fase', $validacion)->update(['orden' => $pasaA]);
            DB::table('fases')->where('id_fase', $otra)->update(['orden' => $validacionEn]);
        });
    }
};
