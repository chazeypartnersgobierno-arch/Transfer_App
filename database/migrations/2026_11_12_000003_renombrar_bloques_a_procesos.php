<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los bloques se llaman procesos: la tabla bloques pasa a procesos y la columna
     * id_bloque pasa a id_proceso (en procesos, actividades y grupos). No se pierden datos.
     *
     * Las llaves foráneas se quitan antes de renombrar y se vuelven a crear al final,
     * para que sus nombres también digan "proceso".
     */
    public function up(): void
    {
        $this->cambiar(de: 'bloque', a: 'proceso');
    }

    public function down(): void
    {
        $this->cambiar(de: 'proceso', a: 'bloque');
    }

    /**
     * Renombra todo de $de a $a. Sirve en los dos sentidos: up() y down().
     */
    private function cambiar(string $de, string $a): void
    {
        $tablaVieja = "{$de}s";
        $tablaNueva = "{$a}s";

        // 1. Quitar las llaves foráneas que usan los nombres viejos.
        Schema::table('actividades', function (Blueprint $table) use ($de) {
            $table->dropForeign("actividades_id_{$de}_foreign");
            // Al quitar la llave, MySQL deja su índice: también se quita y se vuelve a crear abajo.
            $table->dropIndex("actividades_id_{$de}_foreign");
        });

        Schema::table('grupos', function (Blueprint $table) use ($de) {
            $table->dropForeign("grupos_id_{$de}_foreign");
        });

        Schema::table($tablaVieja, function (Blueprint $table) use ($tablaVieja) {
            $table->dropForeign("{$tablaVieja}_id_empresa_foreign");
        });

        // 2. Renombrar la tabla, sus columnas y sus índices.
        Schema::rename($tablaVieja, $tablaNueva);

        Schema::table($tablaNueva, function (Blueprint $table) use ($de, $a, $tablaVieja, $tablaNueva) {
            $table->renameColumn("id_{$de}", "id_{$a}");
            $table->renameIndex("{$tablaVieja}_id_empresa_nombre_unique", "{$tablaNueva}_id_empresa_nombre_unique");
        });

        Schema::table('actividades', function (Blueprint $table) use ($de, $a) {
            $table->renameColumn("id_{$de}", "id_{$a}");
        });

        Schema::table('grupos', function (Blueprint $table) use ($de, $a) {
            $table->renameColumn("id_{$de}", "id_{$a}");
            $table->renameIndex("grupos_id_{$de}_nombre_unique", "grupos_id_{$a}_nombre_unique");
        });

        // 3. Volver a crear las llaves foráneas con los nombres nuevos (ON DELETE RESTRICT, como todas).
        Schema::table($tablaNueva, function (Blueprint $table) {
            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();
        });

        Schema::table('actividades', function (Blueprint $table) use ($a, $tablaNueva) {
            $table->foreign("id_{$a}")->references("id_{$a}")->on($tablaNueva)->restrictOnDelete();
        });

        Schema::table('grupos', function (Blueprint $table) use ($a, $tablaNueva) {
            $table->foreign("id_{$a}")->references("id_{$a}")->on($tablaNueva)->restrictOnDelete();
        });
    }
};
