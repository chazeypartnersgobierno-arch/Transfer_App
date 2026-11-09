<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asignaciones de consultores a empresas. Cada fila es un periodo: cuándo empezó,
     * cuándo terminó y si sigue activa. No se borran: el historial se conserva.
     * No lleva "estado": "activa" cumple ese papel.
     */
    public function up(): void
    {
        Schema::create('usuario_empresa', function (Blueprint $table) {
            $table->increments('id_usuario_empresa');
            $table->unsignedInteger('id_usuario');
            $table->unsignedInteger('id_empresa');

            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable(); // NULL mientras sigue activa
            $table->boolean('activa')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->restrictOnDelete();
            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();

            // Acelera la consulta más frecuente: "las asignaciones activas de este usuario".
            $table->index(['id_usuario', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_empresa');
    }
};
