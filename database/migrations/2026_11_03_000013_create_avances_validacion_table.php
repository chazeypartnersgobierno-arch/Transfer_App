<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // avances_validacion no tiene "estado" ni "fecha_actualizacion" (ver CLAUDE.md).
        Schema::create('avances_validacion', function (Blueprint $table) {
            $table->increments('id_avance_validacion');
            $table->unsignedInteger('id_seguimiento');
            $table->unsignedInteger('id_usuario');

            $table->date('fecha_corte');
            $table->unsignedTinyInteger('porcentaje_esperado');   // 0, 25, 50, 75 o 100
            $table->unsignedTinyInteger('porcentaje_real');       // entero de 0 a 100
            $table->text('comentarios')->nullable();

            $table->dateTime('fecha_creacion')->useCurrent();

            $table->foreign('id_seguimiento')->references('id_seguimiento')->on('seguimientos')->restrictOnDelete();
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->restrictOnDelete();

            // Una sola captura por seguimiento y fecha de corte.
            $table->unique(['id_seguimiento', 'fecha_corte']);
        });

        // Reglas CHECK (Laravel no tiene un método para esto, por eso van en SQL).
        DB::statement('ALTER TABLE avances_validacion ADD CONSTRAINT chk_avances_porcentaje_esperado CHECK (porcentaje_esperado IN (0, 25, 50, 75, 100))');
        DB::statement('ALTER TABLE avances_validacion ADD CONSTRAINT chk_avances_porcentaje_real CHECK (porcentaje_real BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('avances_validacion');
    }
};
