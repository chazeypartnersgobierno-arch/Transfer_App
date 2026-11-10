<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El proceso de una actividad es su bloque (id_bloque): la columna de texto
     * libre proceso repetía ese dato, así que se quita.
     */
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropColumn('proceso');
        });
    }

    /**
     * Vuelve a crear la columna en su lugar (después de macroproceso), pero vacía:
     * los datos que tenía no se recuperan.
     */
    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->string('proceso', 255)->nullable()->after('macroproceso');
        });
    }
};
