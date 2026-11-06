<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class BaseDePruebasTest extends TestCase
{
    public function test_las_pruebas_corren_en_la_base_de_pruebas(): void
    {
        $this->assertSame('transfer_app_testing', config('database.connections.mysql.database'));
    }

    public function test_el_seguro_detiene_las_pruebas_en_otra_base(): void
    {
        config(['database.connections.mysql.database' => 'transfer_app']);

        $this->expectException(RuntimeException::class);

        $this->setUpTraits();
    }
}
