<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicativos', function (Blueprint $table) {
            $table->increments('id_aplicativo');
            $table->unsignedInteger('id_empresa');

            $table->string('nombre', 255);
            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();

            $table->unique(['id_empresa', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicativos');
    }
};
