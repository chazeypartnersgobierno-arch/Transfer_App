<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sesiones no tiene "estado" (ver CLAUDE.md).
        Schema::create('sesiones', function (Blueprint $table) {
            $table->increments('id_sesion');
            $table->unsignedInteger('id_seguimiento');

            $table->date('fecha');
            $table->string('tema', 255);
            $table->string('modalidad', 50);            // valor de lista
            $table->text('comentarios')->nullable();

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_seguimiento')->references('id_seguimiento')->on('seguimientos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};
