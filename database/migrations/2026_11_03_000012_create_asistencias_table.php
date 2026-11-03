<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // asistencias no tiene "estado" (ver CLAUDE.md).
        Schema::create('asistencias', function (Blueprint $table) {
            $table->increments('id_asistencia');
            $table->unsignedInteger('id_sesion');
            $table->unsignedInteger('id_participante');

            $table->boolean('asistio');
            $table->text('comentarios')->nullable();

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_sesion')->references('id_sesion')->on('sesiones')->restrictOnDelete();
            $table->foreign('id_participante')->references('id_participante')->on('participantes')->restrictOnDelete();

            // Un participante tiene una sola asistencia por sesión.
            $table->unique(['id_sesion', 'id_participante']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};
