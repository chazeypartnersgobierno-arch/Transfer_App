<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguimientos', function (Blueprint $table) {
            $table->increments('id_seguimiento');
            $table->unsignedInteger('id_actividad');
            $table->unsignedInteger('id_grupo');

            // Todo puede ir vacío: la app crea los seguimientos vacíos al crear un grupo.
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->string('estado_actividad', 50)->nullable();   // valor de lista
            $table->string('documentacion', 500)->nullable();     // liga
            $table->text('comentarios')->nullable();
            $table->string('responsable', 255)->nullable();
            $table->string('experto', 255)->nullable();
            $table->string('documentador', 255)->nullable();
            $table->string('lider_transferencia', 255)->nullable();
            $table->string('lider_transferencia_gbs', 255)->nullable();

            $table->boolean('estado')->default(true);

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_actividad')->references('id_actividad')->on('actividades')->restrictOnDelete();
            $table->foreign('id_grupo')->references('id_grupo')->on('grupos')->restrictOnDelete();

            // Una actividad solo se sigue una vez por grupo.
            $table->unique(['id_actividad', 'id_grupo']);
        });

        // Regla CHECK: Laravel no tiene un método para esto, por eso va en SQL.
        // Si alguna fecha está vacía (NULL), MySQL no aplica la regla.
        DB::statement('ALTER TABLE seguimientos ADD CONSTRAINT chk_seguimientos_fechas CHECK (fecha_fin >= fecha_inicio)');
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimientos');
    }
};
