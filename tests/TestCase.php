<?php

namespace Tests;

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
}
