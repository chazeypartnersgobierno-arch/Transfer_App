<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->increments('id_grupo');
            $table->unsignedInteger('id_bloque');

            $table->string('nombre', 255);
            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_bloque')->references('id_bloque')->on('bloques')->restrictOnDelete();

            $table->unique(['id_bloque', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};
