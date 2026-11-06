<?php

namespace Database\Seeders;

use App\Enums\RolApp;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea el primer usuario (admin) con los datos de PRIMER_USUARIO_* en .env.
     * Se puede ejecutar varias veces: si el correo ya existe, no cambia nada.
     */
    public function run(): void
    {
        $datos = config('auth.primer_usuario');

        if (empty($datos['nombre']) || empty($datos['correo']) || empty($datos['contrasena'])) {
            throw new RuntimeException('Completa PRIMER_USUARIO_NOMBRE, PRIMER_USUARIO_CORREO y PRIMER_USUARIO_CONTRASENA en .env.');
        }

        Usuario::firstOrCreate(
            ['correo' => $datos['correo']],
            [
                'nombre' => $datos['nombre'],
                'contrasena_hash' => $datos['contrasena'], // el modelo la convierte en hash
                'rol_app' => RolApp::Admin,
                // id_empresa queda en NULL: usuario Chazey, ve todas las empresas.
            ]
        );
    }
}
