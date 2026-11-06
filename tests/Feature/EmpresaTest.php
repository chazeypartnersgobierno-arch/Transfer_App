<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpresaTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private function usuario(RolApp $rol = RolApp::Admin, ?Empresa $empresa = null, bool $estado = true): Usuario
    {
        $this->contador++;

        return Usuario::create([
            'nombre' => 'Prueba',
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
            'id_empresa' => $empresa?->id_empresa,
            'estado' => $estado,
        ]);
    }

    private function empresa(string $sigla = 'EJE1', bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    public function test_sin_sesion_redirige_a_login(): void
    {
        $empresa = $this->empresa();

        $this->get('/empresas')->assertRedirect(route('login'));
        $this->get("/empresas/{$empresa->id_empresa}/editar")->assertRedirect(route('login'));
    }

    public function test_admin_de_chazey_ve_todas_y_sus_acciones(): void
    {
        $this->empresa('EJE1');
        $this->empresa('EJE2');

        $this->actingAs($this->usuario())
            ->get('/empresas')
            ->assertOk()
            ->assertSee('EJE1')
            ->assertSee('EJE2')
            ->assertSee('Nueva empresa')
            ->assertSee('Dar de baja')
            ->assertSee('2 empresas')
            ->assertSee('aria-current="page"', false);
    }

    public function test_admin_de_chazey_crea_una_empresa(): void
    {
        $this->actingAs($this->usuario())
            ->post('/empresas', ['nombre' => 'Nueva S.A.', 'sigla' => 'NSA'])
            ->assertRedirect(route('empresas.index'))
            ->assertSessionHas('exito');

        $this->assertDatabaseHas('empresas', ['nombre' => 'Nueva S.A.', 'sigla' => 'NSA', 'estado' => 1]);
    }

    public function test_admin_de_chazey_edita_y_puede_conservar_la_sigla(): void
    {
        $empresa = $this->empresa('EJE1');

        $this->actingAs($this->usuario())
            ->put("/empresas/{$empresa->id_empresa}", ['nombre' => 'Otro nombre', 'sigla' => 'EJE1'])
            ->assertRedirect(route('empresas.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Otro nombre', $empresa->fresh()->nombre);
    }

    public function test_los_formularios_se_ven(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/empresas/crear')->assertOk()->assertSee('Nueva empresa');
        $this->actingAs($admin)->get("/empresas/{$empresa->id_empresa}/editar")->assertOk()->assertSee('Empresa EJE1');
    }

    public function test_validaciones_con_mensajes_en_espanol(): void
    {
        $this->empresa('EJE1');
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/empresas', ['nombre' => '', 'sigla' => ''])
            ->assertSessionHasErrors(['nombre' => 'Escribe el nombre de la empresa.', 'sigla' => 'Escribe la sigla de la empresa.']);

        $this->actingAs($admin)->post('/empresas', ['nombre' => 'Otra', 'sigla' => 'EJE1'])
            ->assertSessionHasErrors(['sigla' => 'Ya existe una empresa con esta sigla.']);

        $this->actingAs($admin)->post('/empresas', ['nombre' => 'Otra', 'sigla' => str_repeat('A', 21)])
            ->assertSessionHasErrors(['sigla' => 'La sigla no puede tener más de 20 caracteres.']);

        $this->assertSame(1, Empresa::count());
    }

    public function test_no_se_puede_usar_la_sigla_de_otra_empresa_al_editar(): void
    {
        $this->empresa('EJE1');
        $otra = $this->empresa('EJE2');

        $this->actingAs($this->usuario())
            ->put("/empresas/{$otra->id_empresa}", ['nombre' => 'X', 'sigla' => 'EJE1'])
            ->assertSessionHasErrors('sigla');

        $this->assertSame('EJE2', $otra->fresh()->sigla);
    }

    public function test_consultor_de_chazey_ve_todo_pero_no_cambia_nada(): void
    {
        $empresa = $this->empresa('EJE1');
        $consultor = $this->usuario(RolApp::Consultor);
        $url = "/empresas/{$empresa->id_empresa}";

        $this->actingAs($consultor)->get('/empresas')
            ->assertOk()
            ->assertSee('EJE1')
            ->assertDontSee('Nueva empresa')
            ->assertDontSee('Dar de baja');

        $this->actingAs($consultor)->get('/empresas/crear')->assertForbidden();
        $this->actingAs($consultor)->post('/empresas', ['nombre' => 'X', 'sigla' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
        $this->actingAs($consultor)->put($url, ['nombre' => 'X', 'sigla' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();

        $this->assertSame('Empresa EJE1', $empresa->fresh()->nombre);
        $this->assertTrue($empresa->fresh()->estado);
        $this->assertSame(1, Empresa::count());
    }

    public function test_usuario_de_una_empresa_solo_ve_la_suya(): void
    {
        $propia = $this->empresa('PROPIA');
        $this->empresa('AJENA');

        foreach ([RolApp::Admin, RolApp::Consultor] as $rol) {
            $this->actingAs($this->usuario($rol, $propia))->get('/empresas')
                ->assertOk()
                ->assertSee('PROPIA')
                ->assertDontSee('AJENA')
                ->assertDontSee('Nueva empresa')
                ->assertDontSee('Dar de baja');
        }
    }

    public function test_admin_de_una_empresa_no_administra_empresas(): void
    {
        $propia = $this->empresa('PROPIA');
        $ajena = $this->empresa('AJENA');
        $admin = $this->usuario(RolApp::Admin, $propia);

        // La propia existe para este usuario, pero no tiene permiso: 403.
        $this->actingAs($admin)->get('/empresas/crear')->assertForbidden();
        $this->actingAs($admin)->get("/empresas/{$propia->id_empresa}/editar")->assertForbidden();
        $this->actingAs($admin)->patch("/empresas/{$propia->id_empresa}/baja")->assertForbidden();

        // La ajena es como si no existiera: 404.
        $this->actingAs($admin)->get("/empresas/{$ajena->id_empresa}/editar")->assertNotFound();
        $this->actingAs($admin)->put("/empresas/{$ajena->id_empresa}", ['nombre' => 'X', 'sigla' => 'X'])->assertNotFound();
        $this->actingAs($admin)->patch("/empresas/{$ajena->id_empresa}/baja")->assertNotFound();

        $this->assertTrue($ajena->fresh()->estado);
        $this->assertSame('Empresa AJENA', $ajena->fresh()->nombre);
    }

    public function test_dar_de_baja_no_borra_y_reactivar_la_recupera(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/empresas')->patch("/empresas/{$empresa->id_empresa}/baja")
            ->assertRedirect('/empresas')
            ->assertSessionHas('exito');
        $this->assertFalse($empresa->fresh()->estado);
        $this->assertSame(1, Empresa::count());

        $this->actingAs($admin)->from('/empresas')->patch("/empresas/{$empresa->id_empresa}/reactivar")
            ->assertSessionHas('exito');
        $this->assertTrue($empresa->fresh()->estado);
    }

    public function test_la_baja_se_bloquea_con_usuarios_activos(): void
    {
        $empresa = $this->empresa();
        $this->usuario(RolApp::Consultor, $empresa);

        $this->actingAs($this->usuario())->from('/empresas')->patch("/empresas/{$empresa->id_empresa}/baja")
            ->assertRedirect('/empresas')
            ->assertSessionHas('error', "No se puede dar de baja {$empresa->nombre}: todavía tiene usuarios activos.");

        $this->assertTrue($empresa->fresh()->estado);
    }

    public function test_la_baja_procede_si_sus_usuarios_estan_de_baja(): void
    {
        $empresa = $this->empresa();
        $this->usuario(RolApp::Consultor, $empresa, false);

        $this->actingAs($this->usuario())->patch("/empresas/{$empresa->id_empresa}/baja")
            ->assertSessionHas('exito');

        $this->assertFalse($empresa->fresh()->estado);
    }

    public function test_las_dadas_de_baja_se_ocultan_por_defecto(): void
    {
        $this->empresa('ACTIVA');
        $this->empresa('BAJA', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/empresas')->assertSee('ACTIVA')->assertDontSee('Empresa BAJA');

        $this->actingAs($admin)->get('/empresas?ver_bajas=1')
            ->assertSee('ACTIVA')
            ->assertSee('Empresa BAJA')
            ->assertSee('Reactivar');
    }

    public function test_la_busqueda_filtra_por_nombre_y_sigla(): void
    {
        Empresa::create(['nombre' => 'Primera', 'sigla' => 'AAA']);
        Empresa::create(['nombre' => 'Segunda', 'sigla' => 'BBB']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/empresas?buscar=Prim')->assertSee('Primera')->assertDontSee('Segunda');
        $this->actingAs($admin)->get('/empresas?buscar=BBB')->assertSee('Segunda')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/empresas?buscar=nada')->assertSee('Ninguna empresa coincide con tu búsqueda.');
    }

    public function test_en_la_busqueda_los_comodines_son_texto_normal(): void
    {
        Empresa::create(['nombre' => 'Primera', 'sigla' => 'AAA']);

        $this->actingAs($this->usuario())->get('/empresas?buscar=%25')->assertDontSee('Primera');
    }

    public function test_buscar_con_un_arreglo_no_rompe(): void
    {
        $this->actingAs($this->usuario())->get('/empresas?buscar[]=x')->assertOk();
    }

    public function test_pagina_en_espanol_y_conserva_la_busqueda(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            Empresa::create(['nombre' => sprintf('Demo %02d', $i), 'sigla' => "D{$i}"]);
        }

        $this->actingAs($this->usuario())->get('/empresas?buscar=Demo')
            ->assertSee('16 empresas')
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertSee('Siguiente')
            ->assertSee('buscar=Demo&amp;page=2', false)
            ->assertDontSee('Showing');
    }

    public function test_sin_empresas_lo_indica(): void
    {
        $this->actingAs($this->usuario())->get('/empresas')->assertSee('Todavía no hay empresas registradas.');
    }

    public function test_el_nombre_se_escapa_en_la_pantalla(): void
    {
        Empresa::create(['nombre' => '<script>alert(1)</script>', 'sigla' => 'XSS']);

        $this->actingAs($this->usuario())->get('/empresas')->assertDontSee('<script>alert(1)</script>', false);
    }
}
