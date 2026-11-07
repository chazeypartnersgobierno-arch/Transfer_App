<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La fase pasa de la actividad al seguimiento, y la actividad recibe un tipo:
     * Onboarding (1 seguimiento por grupo) o Transición (3 seguimientos por grupo, uno por fase).
     * Los datos existentes se conservan: el tipo y la fase salen del id_fase que ya tenían.
     */
    public function up(): void
    {
        // 1. Agrega los campos nuevos, todavía opcionales para poder llenarlos.
        Schema::table('actividades', function (Blueprint $table) {
            $table->string('tipo', 20)->nullable()->after('id_bloque');
        });

        Schema::table('seguimientos', function (Blueprint $table) {
            $table->unsignedInteger('id_fase')->nullable()->after('id_grupo');
            $table->date('fecha_cierre')->nullable()->after('fecha_fin');
        });

        // 2. Llena el tipo según la fase actual: la de orden 1 es Onboarding.
        $idsOnboarding = DB::table('fases')->where('orden', 1)->pluck('id_fase');

        DB::table('actividades')->whereIn('id_fase', $idsOnboarding)->update(['tipo' => 'Onboarding']);
        DB::table('actividades')->whereNull('tipo')->update(['tipo' => 'Transición']);

        // 3. Cada seguimiento hereda la fase de su actividad.
        DB::table('seguimientos')
            ->join('actividades', 'actividades.id_actividad', '=', 'seguimientos.id_actividad')
            ->update(['seguimientos.id_fase' => DB::raw('actividades.id_fase')]);

        // 4. Ya con datos, los campos pasan a ser obligatorios.
        Schema::table('actividades', function (Blueprint $table) {
            $table->string('tipo', 20)->nullable(false)->change();
        });

        Schema::table('seguimientos', function (Blueprint $table) {
            $table->unsignedInteger('id_fase')->nullable(false)->change();
            $table->foreign('id_fase')->references('id_fase')->on('fases')->restrictOnDelete();

            // Primero el valor único nuevo: la FK de id_actividad necesita un índice en todo momento.
            $table->unique(['id_actividad', 'id_grupo', 'id_fase']);
            $table->dropUnique(['id_actividad', 'id_grupo']);
        });

        // 5. La actividad ya no tiene fase.
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropForeign(['id_fase']);
            $table->dropColumn('id_fase');
        });
    }

    /**
     * Regresa a la estructura anterior. Solo es posible si cada actividad tiene un
     * seguimiento por grupo, porque antes no podía haber más. Las de Transición vuelven
     * a la fase de orden 2.
     */
    public function down(): void
    {
        $hayVarios = DB::table('seguimientos')
            ->select('id_actividad', 'id_grupo')
            ->groupBy('id_actividad', 'id_grupo')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hayVarios) {
            throw new RuntimeException('No se puede revertir: hay actividades con varios seguimientos por grupo.');
        }

        Schema::table('actividades', function (Blueprint $table) {
            $table->unsignedInteger('id_fase')->nullable()->after('id_empresa');
        });

        $fases = DB::table('fases')->pluck('id_fase', 'orden');

        DB::table('actividades')->where('tipo', 'Onboarding')->update(['id_fase' => $fases[1] ?? null]);
        DB::table('actividades')->where('tipo', 'Transición')->update(['id_fase' => $fases[2] ?? null]);

        Schema::table('actividades', function (Blueprint $table) {
            $table->unsignedInteger('id_fase')->nullable(false)->change();
            $table->foreign('id_fase')->references('id_fase')->on('fases')->restrictOnDelete();
            $table->dropColumn('tipo');
        });

        Schema::table('seguimientos', function (Blueprint $table) {
            $table->unique(['id_actividad', 'id_grupo']);
            $table->dropUnique(['id_actividad', 'id_grupo', 'id_fase']);
            $table->dropForeign(['id_fase']);
            $table->dropColumn(['id_fase', 'fecha_cierre']);
        });
    }
};
