<?php

use App\Enums\RolApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La empresa deja de ser un campo fijo del usuario:
     * - El administrador siempre es de Chazey y ve todas las empresas.
     * - El consultor ve la empresa de su asignación activa (tabla usuario_empresa).
     * También se quita id_participante: los participantes no son usuarios.
     */
    public function up(): void
    {
        // Ya no existen admins de una empresa. No se decide por nadie qué hacer con ellos:
        // se detiene y avisa.
        $adminsDeEmpresa = DB::table('usuarios')
            ->where('rol_app', RolApp::Admin->value)
            ->whereNotNull('id_empresa')
            ->pluck('correo');

        if ($adminsDeEmpresa->isNotEmpty()) {
            throw new RuntimeException('Estos administradores tienen empresa y ya no puede haber admins de empresa: '
                .$adminsDeEmpresa->implode(', ').'. Cámbiales el rol a consultor o quítales la empresa antes de correr esta migración.');
        }

        $conParticipante = DB::table('usuarios')->whereNotNull('id_participante')->pluck('correo');

        if ($conParticipante->isNotEmpty()) {
            throw new RuntimeException('Estos usuarios están ligados a un participante: '
                .$conParticipante->implode(', ').'. Quita el vínculo (id_participante = NULL) antes de correr esta migración.');
        }

        // Cada consultor con empresa recibe una asignación activa desde que se creó su usuario.
        DB::table('usuarios')
            ->whereNotNull('id_empresa')
            ->orderBy('id_usuario')
            ->get(['id_usuario', 'id_empresa', 'fecha_creacion'])
            ->each(fn ($usuario) => DB::table('usuario_empresa')->insert([
                'id_usuario' => $usuario->id_usuario,
                'id_empresa' => $usuario->id_empresa,
                'fecha_inicio' => substr((string) $usuario->fecha_creacion, 0, 10),
                'activa' => true,
            ]));

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['id_empresa']);
            $table->dropForeign(['id_participante']);
            $table->dropColumn(['id_empresa', 'id_participante']);
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedInteger('id_empresa')->nullable()->after('id_usuario');
            $table->unsignedInteger('id_participante')->nullable()->after('id_empresa');

            $table->foreign('id_empresa')->references('id_empresa')->on('empresas')->restrictOnDelete();
            $table->foreign('id_participante')->references('id_participante')->on('participantes')->restrictOnDelete();
        });

        // Devuelve a cada usuario la empresa de su asignación activa.
        DB::table('usuario_empresa')
            ->where('activa', true)
            ->get(['id_usuario', 'id_empresa'])
            ->each(fn ($asignacion) => DB::table('usuarios')
                ->where('id_usuario', $asignacion->id_usuario)
                ->update(['id_empresa' => $asignacion->id_empresa]));

        DB::table('usuario_empresa')->delete();
    }
};
