<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * up(): crea la tabla. Se ejecuta con "php artisan migrate".
     */
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            // INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            $table->increments('id_empresa');

            $table->string('nombre', 255);
            $table->string('sigla', 20)->unique();   // no se repite

            // 1 = activo, 0 = baja (no se borra información)
            $table->boolean('estado')->default(true);

            // Fechas automáticas: MySQL pone la fecha al crear y al actualizar.
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * down(): deshace lo de up(). Se ejecuta con "php artisan migrate:rollback".
     */
    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
