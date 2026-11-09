<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Autor de la última captura o corrección (igual que en avances_validacion).
     * NULL en los registros que ya existían y en los seguimientos que genera la app
     * mientras nadie los edite. La asistencia toma el autor de su sesión.
     */
    public function up(): void
    {
        Schema::table('seguimientos', function (Blueprint $table) {
            $table->unsignedInteger('id_usuario')->nullable()->after('id_fase');
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->restrictOnDelete();
        });

        Schema::table('sesiones', function (Blueprint $table) {
            $table->unsignedInteger('id_usuario')->nullable()->after('id_seguimiento');
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropForeign(['id_usuario']);
            $table->dropColumn('id_usuario');
        });

        Schema::table('seguimientos', function (Blueprint $table) {
            $table->dropForeign(['id_usuario']);
            $table->dropColumn('id_usuario');
        });
    }
};
