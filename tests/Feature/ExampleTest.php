<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_la_pagina_de_inicio_exige_iniciar_sesion(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
