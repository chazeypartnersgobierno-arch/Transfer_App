<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participantes', function (Blueprint $table) {
            $table->increments('id_participante');
            $table->unsignedInteger('id_grupo');

            $table->string('nombre', 255);
            $table->string('apellidos', 255);
            $table->string('correo', 255)->unique();
            $table->string('rol', 50);                  // valor de lista (se define después)
            $table->date('fecha_ingreso')->nullable();
            $table->text('comentarios')->nullable();

            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_grupo')->references('id_grupo')->on('grupos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participantes');
    }
};
