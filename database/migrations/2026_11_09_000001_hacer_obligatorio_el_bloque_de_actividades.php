<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Todas las actividades llevan un bloque: el Onboarding es distinto en cada bloque,
     * así que ya no existen actividades "para todos los bloques" (id_bloque NULL).
     */
    public function up(): void
    {
        // No se inventa un bloque para las actividades que no lo tengan: se detiene y avisa.
        $sinBloque = DB::table('actividades')->whereNull('id_bloque')->count();

        if ($sinBloque > 0) {
            throw new RuntimeException("Hay {$sinBloque} actividades sin bloque. Asígnales un bloque antes de correr esta migración.");
        }

        // La FK se quita y se vuelve a poner para poder cambiar la columna sin problemas.
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropForeign(['id_bloque']);
        });

        Schema::table('actividades', function (Blueprint $table) {
            $table->unsignedInteger('id_bloque')->nullable(false)->change();
            $table->foreign('id_bloque')->references('id_bloque')->on('bloques')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropForeign(['id_bloque']);
        });

        Schema::table('actividades', function (Blueprint $table) {
            $table->unsignedInteger('id_bloque')->nullable()->change();
            $table->foreign('id_bloque')->references('id_bloque')->on('bloques')->restrictOnDelete();
        });
    }
};
