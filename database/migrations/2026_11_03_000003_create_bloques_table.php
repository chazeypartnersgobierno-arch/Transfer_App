<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloques', function (Blueprint $table) {
            $table->increments('id_bloque');

            // Llave foránea: mismo tipo que la PK de empresas (INT UNSIGNED).
            $table->unsignedInteger('id_empresa');

            $table->string('nombre', 255);
            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // La relación: id_empresa apunta a empresas.id_empresa.
            // restrictOnDelete = ON DELETE RESTRICT (no deja borrar la empresa si tiene bloques).
            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();

            // Una empresa no puede tener dos bloques con el mismo nombre.
            $table->unique(['id_empresa', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloques');
    }
};
