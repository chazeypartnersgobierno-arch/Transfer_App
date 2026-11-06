<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutPrincipalTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(RolApp $rol): Usuario
    {
        return Usuario::create([
            'nombre' => 'Ana Prueba',
            'correo' => $rol->value.'@prueba.test',
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]);
    }

    public function test_inicio_muestra_barra_y_menu_con_la_opcion_activa(): void
    {
        $this->actingAs($this->usuario(RolApp::Admin))
            ->get('/')
            ->assertOk()
            ->assertSee('Hola, Ana Prueba')
            ->assertSee('Administrador')
            ->assertSee('barra-logo', false)
            ->assertSee('aria-label="Abrir menú"', false)
            ->assertSee('id="menu"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('action="'.route('logout').'"', false);
    }

    public function test_inicio_muestra_la_etiqueta_del_rol_consultor(): void
    {
        $this->actingAs($this->usuario(RolApp::Consultor))
            ->get('/')
            ->assertOk()
            ->assertSee('Consultor')
            ->assertDontSee('Administrador');
    }

    public function test_el_logo_del_layout_sale_de_la_configuracion(): void
    {
        config(['marca.logo' => 'img/otro.png', 'marca.logo_alt' => 'Otra Empresa']);

        $this->actingAs($this->usuario(RolApp::Admin))
            ->get('/')
            ->assertSee('img/otro.png', false)
            ->assertSee('alt="Otra Empresa"', false);
    }
}
