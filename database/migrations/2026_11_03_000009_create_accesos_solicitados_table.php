<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_solicitados', function (Blueprint $table) {
            $table->increments('id_acceso_solicitado');
            $table->unsignedInteger('id_participante');
            $table->unsignedInteger('id_aplicativo');

            $table->date('fecha_solicitud');
            $table->date('fecha_otorgado')->nullable();
            // Aquí "estado" es el estado del acceso (valor de lista), NO el BOOLEAN de baja.
            $table->string('estado', 50);
            $table->text('comentarios')->nullable();

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_participante')->references('id_participante')->on('participantes')->restrictOnDelete();
            $table->foreign('id_aplicativo')->references('id_aplicativo')->on('aplicativos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_solicitados');
    }
};
