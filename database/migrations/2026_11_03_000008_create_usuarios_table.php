<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->increments('id_usuario');
            // NULL = usuario Chazey (ve todas las empresas).
            $table->unsignedInteger('id_empresa')->nullable();
            $table->unsignedInteger('id_participante')->nullable();

            $table->string('nombre', 255);
            $table->string('correo', 255)->unique();
            // Solo el hash bcrypt (60 caracteres); 255 deja margen si cambia el algoritmo.
            $table->string('contrasena_hash', 255);
            $table->string('rol_app', 50);              // valor de lista (se define después)

            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();
            $table->foreign('id_participante')->references('id_participante')->on('participantes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
