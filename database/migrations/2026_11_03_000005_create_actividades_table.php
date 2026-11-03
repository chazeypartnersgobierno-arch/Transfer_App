<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividades', function (Blueprint $table) {
            $table->increments('id_actividad');
            $table->unsignedInteger('id_empresa');
            $table->unsignedInteger('id_fase');
            // NULL = actividad de Onboarding (aplica a todos los bloques).
            $table->unsignedInteger('id_bloque')->nullable();

            // nullable() = el campo puede quedar vacío.
            $table->string('macroproceso', 255)->nullable();
            $table->string('proceso', 255)->nullable();
            $table->string('subproceso', 255)->nullable();
            $table->string('nombre', 500);

            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();
            $table->foreign('id_fase')->references('id_fase')->on('fases')->restrictOnDelete();
            $table->foreign('id_bloque')->references('id_bloque')->on('bloques')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividades');
    }
};
