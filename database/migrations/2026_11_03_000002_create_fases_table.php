<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // fases no tiene "estado" (ver CLAUDE.md).
        Schema::create('fases', function (Blueprint $table) {
            $table->increments('id_fase');

            $table->string('nombre', 100);
            $table->unsignedTinyInteger('orden');      // 1, 2, 3, 4...
            $table->boolean('tiene_sesiones');         // 1 = registra sesiones y asistencia

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fases');
    }
};
