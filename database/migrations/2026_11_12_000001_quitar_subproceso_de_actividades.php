<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El subproceso ya no se usa: se quita la columna de actividades.
     * Una tabla que ya existe se cambia con una migración nueva, no editando la que la creó.
     */
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropColumn('subproceso');
        });
    }

    /**
     * Vuelve a crear la columna en su lugar (después de proceso), pero vacía:
     * los datos que tenía no se recuperan.
     */
    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->string('subproceso', 255)->nullable()->after('proceso');
        });
    }
};
