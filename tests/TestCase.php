<?php

namespace Tests;

use App\Enums\RolApp;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seguro: las pruebas borran y recrean las tablas, así que se detienen de
     * inmediato si no están conectadas a la base de pruebas. Laravel llama a este
     * método antes de tocar la base de datos.
     */
    protected function setUpTraits()
    {
        $base = config('database.connections.'.config('database.default').'.database');

        if ($base !== 'transfer_app_testing') {
            throw new RuntimeException("Las pruebas solo pueden correr en transfer_app_testing, no en [{$base}].");
        }

        return parent::setUpTraits();
    }

    /**
     * Asigna un consultor a una empresa (asignación activa desde hoy). Sin empresa no hace nada:
     * el consultor queda sin asignación y no ve datos; el admin no lleva asignaciones.
     */
    protected function asignarEmpresa(Usuario $usuario, ?Empresa $empresa): Usuario
    {
        if ($empresa === null) {
            return $usuario;
        }

        if ($usuario->rol_app === RolApp::Admin) {
            throw new RuntimeException('El admin es de Chazey: no se asigna a una empresa.');
        }

        $usuario->asignaciones()->create([
            'id_empresa' => $empresa->id_empresa,
            'fecha_inicio' => today()->toDateString(),
            'activa' => true,
        ]);

        return $usuario;
    }
}
