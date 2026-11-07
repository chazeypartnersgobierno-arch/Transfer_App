<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Actividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActividadTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
    }

    private function usuario(RolApp $rol = RolApp::Admin, ?Empresa $empresa = null): Usuario
    {
        $this->contador++;

        return Usuario::create([
            'nombre' => 'Prueba',
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
            'id_empresa' => $empresa?->id_empresa,
        ]);
    }

    private function empresa(string $sigla = 'PROPIA', bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    private function bloque(Empresa $empresa, string $nombre = 'Tesorería', bool $estado = true): Bloque
    {
        return Bloque::create(['id_empresa' => $empresa->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function fase(int $orden): Fase
    {
        return Fase::where('orden', $orden)->sole();
    }

    private function actividad(Empresa $empresa, int $orden = 1, ?Bloque $bloque = null, array $extra = []): Actividad
    {
        return Actividad::create([
            'id_empresa' => $empresa->id_empresa,
            'id_fase' => $this->fase($orden)->id_fase,
            'id_bloque' => $bloque?->id_bloque,
            'nombre' => 'Actividad uno',
            ...$extra,
        ]);
    }

    /** Datos válidos para crear una actividad de Transferencia. */
    private function datos(Empresa $empresa, Bloque $bloque, array $cambios = []): array
    {
        return [
            'id_empresa' => $empresa->id_empresa,
            'id_fase' => $this->fase(2)->id_fase,
            'id_bloque' => $bloque->id_bloque,
            'nombre' => 'Conciliar cuentas',
            ...$cambios,
        ];
    }

    public function test_sin_sesion_redirige_a_login(): void
    {
        $actividad = $this->actividad($this->empresa());

        $this->get('/actividades')->assertRedirect(route('login'));
        $this->get("/actividades/{$actividad->id_actividad}/editar")->assertRedirect(route('login'));
    }

    public function test_lista_muestra_datos_en_orden_de_fase_y_todos_en_onboarding(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa);
        $this->actividad($empresa, 4, $bloque, ['nombre' => 'A de validacion', 'macroproceso' => 'Finanzas', 'proceso' => 'Pagos', 'subproceso' => 'Cierre']);
        $this->actividad($empresa, 2, $bloque, ['nombre' => 'B de transferencia']);
        $this->actividad($empresa, 1, null, ['nombre' => 'C de onboarding']);

        $this->actingAs($this->usuario())->get('/actividades')
            ->assertOk()
            ->assertSeeInOrder(['C de onboarding', 'B de transferencia', 'A de validacion'])
            ->assertSee('Finanzas › Pagos › Cierre')
            ->assertSee('Todos')
            ->assertSee('Tesorería')
            ->assertSee('Empresa PROPIA')
            ->assertSee('Nueva actividad')
            ->assertSee('3 actividades');
    }

    public function test_filtros_por_empresa_fase_y_bloque(): void
    {
        $a = $this->empresa('AAA');
        $b = $this->empresa('BBB');
        $bloqueA1 = $this->bloque($a, 'Uno');
        $bloqueA2 = $this->bloque($a, 'Dos');
        $bloqueB = $this->bloque($b, 'Tres');
        $this->actividad($a, 1, null, ['nombre' => 'En onboarding']);
        $this->actividad($a, 2, $bloqueA1, ['nombre' => 'En bloque uno']);
        $this->actividad($a, 2, $bloqueA2, ['nombre' => 'En bloque dos']);
        $this->actividad($b, 3, $bloqueB, ['nombre' => 'De la otra']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/actividades?id_empresa={$b->id_empresa}")
            ->assertSee('De la otra')->assertDontSee('En onboarding');

        $this->actingAs($admin)->get('/actividades?id_fase='.$this->fase(1)->id_fase)
            ->assertSee('En onboarding')->assertDontSee('En bloque uno');

        $this->actingAs($admin)->get("/actividades?id_bloque={$bloqueA1->id_bloque}")
            ->assertSee('En bloque uno')->assertDontSee('En bloque dos');

        // Con otra empresa elegida, un bloque de la anterior se ignora, no deja la lista vacía.
        $this->actingAs($admin)->get("/actividades?id_empresa={$b->id_empresa}&id_bloque={$bloqueA1->id_bloque}")
            ->assertSee('De la otra');
    }

    public function test_busca_en_nombre_y_procesos_con_comodines_como_texto(): void
    {
        $empresa = $this->empresa();
        $this->actividad($empresa, 1, null, ['nombre' => 'Primera']);
        $this->actividad($empresa, 1, null, ['nombre' => 'Segunda', 'macroproceso' => 'Finanzas']);
        $this->actividad($empresa, 1, null, ['nombre' => 'Tercera', 'proceso' => 'Pagos']);
        $this->actividad($empresa, 1, null, ['nombre' => 'Cuarta', 'subproceso' => 'Cierre']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?buscar=Primera')->assertSee('Primera')->assertDontSee('Segunda');
        $this->actingAs($admin)->get('/actividades?buscar=Finanzas')->assertSee('Segunda')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar=Pagos')->assertSee('Tercera')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar=Cierre')->assertSee('Cuarta')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar=%25')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar[]=x&id_fase[]=y&id_bloque[]=z')->assertOk();
    }

    public function test_pagina_y_conserva_filtros(): void
    {
        $empresa = $this->empresa();

        for ($i = 1; $i <= 16; $i++) {
            $this->actividad($empresa, 1, null, ['nombre' => sprintf('Demo %02d', $i)]);
        }

        $this->actingAs($this->usuario())->get('/actividades?buscar=Demo')
            ->assertSee('16 actividades')
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertSee('buscar=Demo&amp;page=2', false);
    }

    public function test_crea_una_actividad_con_bloque_y_opcionales_vacios(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa);

        $this->actingAs($this->usuario())
            ->post('/actividades', $this->datos($empresa, $bloque, ['macroproceso' => '', 'proceso' => 'Pagos', 'subproceso' => '']))
            ->assertRedirect(route('actividades.index'))
            ->assertSessionHas('exito');

        $actividad = Actividad::sole();
        $this->assertSame($bloque->id_bloque, $actividad->id_bloque);
        $this->assertNull($actividad->macroproceso);
        $this->assertSame('Pagos', $actividad->proceso);
        $this->assertNull($actividad->subproceso);
    }

    public function test_onboarding_no_guarda_bloque_aunque_lo_envien(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa);

        $this->actingAs($this->usuario())
            ->post('/actividades', $this->datos($empresa, $bloque, ['id_fase' => $this->fase(1)->id_fase]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Actividad::sole()->id_bloque);
    }

    public function test_onboarding_se_reconoce_aunque_cambie_de_nombre(): void
    {
        $empresa = $this->empresa();
        $this->fase(1)->update(['nombre' => 'Inducción']);

        $this->actingAs($this->usuario())->post('/actividades', [
            'id_empresa' => $empresa->id_empresa,
            'id_fase' => $this->fase(1)->id_fase,
            'nombre' => 'Sin bloque',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Actividad::sole()->id_bloque);
    }

    public function test_las_demas_fases_exigen_bloque(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();

        foreach ([2, 3, 4] as $orden) {
            $this->actingAs($admin)->post('/actividades', [
                'id_empresa' => $empresa->id_empresa,
                'id_fase' => $this->fase($orden)->id_fase,
                'nombre' => 'Sin bloque',
            ])->assertSessionHasErrors(['id_bloque' => 'Elige un bloque.']);
        }

        $this->assertSame(0, Actividad::count());
    }

    public function test_el_bloque_debe_ser_activo_y_de_la_misma_empresa(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $admin = $this->usuario();
        $mensaje = 'El bloque no existe, está dado de baja o es de otra empresa.';

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $this->bloque($otra)))
            ->assertSessionHasErrors(['id_bloque' => $mensaje]);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $this->bloque($empresa, 'Inactivo', false)))
            ->assertSessionHasErrors(['id_bloque' => $mensaje]);

        $this->assertSame(0, Actividad::count());
    }

    public function test_validaciones_generales_en_espanol(): void
    {
        $empresa = $this->empresa();
        $inactiva = $this->empresa('INACTIVA', false);
        $bloque = $this->bloque($empresa);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', [])
            ->assertSessionHasErrors([
                'id_empresa' => 'Elige una empresa.',
                'id_fase' => 'Elige una fase.',
                'nombre' => 'Escribe el nombre de la actividad.',
            ]);

        $this->actingAs($admin)->post('/actividades', $this->datos($inactiva, $bloque))
            ->assertSessionHasErrors(['id_empresa' => 'La empresa no existe o está dada de baja.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $bloque, ['id_fase' => 9999]))
            ->assertSessionHasErrors(['id_fase' => 'Elige una fase de la lista.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $bloque, ['id_fase' => ['x']]))
            ->assertSessionHasErrors('id_fase');

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $bloque, ['nombre' => str_repeat('A', 501)]))
            ->assertSessionHasErrors(['nombre' => 'El nombre no puede tener más de 500 caracteres.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $bloque, ['proceso' => str_repeat('A', 256)]))
            ->assertSessionHasErrors(['proceso' => 'El proceso no puede tener más de 255 caracteres.']);

        $this->assertSame(0, Actividad::count());
    }

    public function test_rechaza_una_actividad_repetida_incluso_dada_de_baja(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa);
        $admin = $this->usuario();
        $mensaje = 'Ya existe una actividad igual. Si está dada de baja, puedes reactivarla.';
        $datos = $this->datos($empresa, $bloque, ['proceso' => 'Pagos']);

        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors(['nombre' => $mensaje]);

        Actividad::sole()->update(['estado' => false]);
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors(['nombre' => $mensaje]);

        $this->assertSame(1, Actividad::count());
    }

    public function test_detecta_repetidas_sin_bloque_y_sin_procesos(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();
        $datos = ['id_empresa' => $empresa->id_empresa, 'id_fase' => $this->fase(1)->id_fase, 'nombre' => 'Inducción general'];

        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors('nombre');

        $this->assertSame(1, Actividad::count());
    }

    public function test_el_mismo_nombre_se_permite_si_cambia_otro_dato(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $bloque = $this->bloque($empresa, 'Uno');
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $bloque))->assertSessionHasNoErrors();

        $variantes = [
            'otro proceso' => $this->datos($empresa, $bloque, ['proceso' => 'Otro']),
            'otro macroproceso' => $this->datos($empresa, $bloque, ['macroproceso' => 'Otro']),
            'otro subproceso' => $this->datos($empresa, $bloque, ['subproceso' => 'Otro']),
            'otra fase' => $this->datos($empresa, $bloque, ['id_fase' => $this->fase(3)->id_fase]),
            'otro bloque' => $this->datos($empresa, $this->bloque($empresa, 'Dos')),
            'otra empresa' => $this->datos($otra, $this->bloque($otra, 'Uno')),
        ];

        foreach ($variantes as $datos) {
            $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        }

        $this->assertSame(7, Actividad::count());
    }

    public function test_formulario_de_chazey_trae_lo_necesario_para_filtrar(): void
    {
        $empresa = $this->empresa('AAA');
        $this->empresa('INACTIVA', false);
        $bloque = $this->bloque($empresa, 'Activo');
        $this->bloque($empresa, 'Bloque inactivo', false);

        $this->actingAs($this->usuario())->get('/actividades/crear')
            ->assertOk()
            ->assertSee('data-formulario-actividad', false)
            ->assertSee('data-sin-bloque="true"', false)
            ->assertSee('data-empresa="'.$empresa->id_empresa.'"', false)
            ->assertSee('value="'.$bloque->id_bloque.'"', false)
            ->assertSee('name="id_empresa"', false)
            ->assertDontSee('Empresa INACTIVA')
            ->assertDontSee('Bloque inactivo');
    }

    public function test_sin_empresas_activas_no_abre_el_formulario(): void
    {
        $this->actingAs($this->usuario())->get('/actividades/crear')
            ->assertRedirect(route('actividades.index'))
            ->assertSessionHas('error', 'Primero registra una empresa activa.');
    }

    public function test_admin_de_empresa_solo_ve_y_crea_en_la_suya(): void
    {
        $propia = $this->empresa('PROPIA');
        $ajena = $this->empresa('AJENA');
        $bloquePropio = $this->bloque($propia, 'Bloque propio');
        $bloqueAjeno = $this->bloque($ajena, 'Bloque ajeno');
        $this->actividad($propia, 1, null, ['nombre' => 'Actividad propia']);
        $this->actividad($ajena, 1, null, ['nombre' => 'Actividad ajena']);
        $admin = $this->usuario(RolApp::Admin, $propia);

        $this->actingAs($admin)->get('/actividades')
            ->assertSee('Actividad propia')
            ->assertSee('Bloque propio')
            ->assertDontSee('Actividad ajena')
            ->assertDontSee('Bloque ajeno')
            ->assertDontSee('Todas las empresas');

        $this->actingAs($admin)->get("/actividades?id_empresa={$ajena->id_empresa}")->assertDontSee('Actividad ajena');

        $this->actingAs($admin)->get('/actividades/crear')
            ->assertOk()
            ->assertDontSee('name="id_empresa"', false)
            ->assertSee('Bloque propio')
            ->assertDontSee('Bloque ajeno');

        // Aunque manipule el formulario, se guarda en su empresa.
        $this->actingAs($admin)->post('/actividades', $this->datos($ajena, $bloquePropio, ['nombre' => 'Manipulada']))
            ->assertSessionHasNoErrors();
        $this->assertSame($propia->id_empresa, Actividad::where('nombre', 'Manipulada')->sole()->id_empresa);

        // Tampoco puede usar un bloque ajeno.
        $this->actingAs($admin)->post('/actividades', $this->datos($propia, $bloqueAjeno, ['nombre' => 'Con ajeno']))
            ->assertSessionHasErrors('id_bloque');
    }

    public function test_admin_de_empresa_recibe_404_con_actividades_ajenas(): void
    {
        $propia = $this->empresa('PROPIA');
        $ajena = $this->actividad($this->empresa('AJENA'));
        $admin = $this->usuario(RolApp::Admin, $propia);
        $url = "/actividades/{$ajena->id_actividad}";

        $this->actingAs($admin)->get("{$url}/editar")->assertNotFound();
        $this->actingAs($admin)->put($url, ['nombre' => 'X'])->assertNotFound();
        $this->actingAs($admin)->patch("{$url}/baja")->assertNotFound();
        $this->actingAs($admin)->patch("{$url}/reactivar")->assertNotFound();

        $this->assertSame('Actividad uno', $ajena->fresh()->nombre);
        $this->assertTrue($ajena->fresh()->estado);
    }

    public function test_consultor_ve_pero_no_cambia_nada(): void
    {
        $empresa = $this->empresa();
        $actividad = $this->actividad($empresa);
        $bloque = $this->bloque($empresa);
        $url = "/actividades/{$actividad->id_actividad}";

        foreach ([$this->usuario(RolApp::Consultor), $this->usuario(RolApp::Consultor, $empresa)] as $consultor) {
            $this->actingAs($consultor)->get('/actividades')
                ->assertOk()
                ->assertSee('Actividad uno')
                ->assertDontSee('Nueva actividad')
                ->assertDontSee('Dar de baja');

            $this->actingAs($consultor)->get('/actividades/crear')->assertForbidden();
            $this->actingAs($consultor)->post('/actividades', $this->datos($empresa, $bloque))->assertForbidden();
            $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
            $this->actingAs($consultor)->put($url, ['nombre' => 'X'])->assertForbidden();
            $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
            $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();
        }

        $this->assertSame(1, Actividad::count());
        $this->assertSame('Actividad uno', $actividad->fresh()->nombre);
        $this->assertTrue($actividad->fresh()->estado);
    }

    public function test_al_editar_solo_cambian_los_textos(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $bloque = $this->bloque($empresa, 'Uno');
        $otroBloque = $this->bloque($empresa, 'Dos');
        $actividad = $this->actividad($empresa, 2, $bloque);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/actividades/{$actividad->id_actividad}/editar")
            ->assertOk()
            ->assertSee('Empresa AAA')
            ->assertSee('Transferencia')
            ->assertDontSee('name="id_empresa"', false)
            ->assertDontSee('name="id_fase"', false)
            ->assertDontSee('name="id_bloque"', false)
            ->assertDontSee('data-formulario-actividad', false);

        $this->actingAs($admin)->put("/actividades/{$actividad->id_actividad}", [
            'nombre' => 'Renombrada',
            'macroproceso' => 'Macro',
            'proceso' => 'Proc',
            'subproceso' => 'Sub',
            'id_empresa' => $otra->id_empresa,
            'id_fase' => $this->fase(3)->id_fase,
            'id_bloque' => $otroBloque->id_bloque,
        ])->assertSessionHasNoErrors();

        $actividad->refresh();
        $this->assertSame('Renombrada', $actividad->nombre);
        $this->assertSame('Macro', $actividad->macroproceso);
        $this->assertSame('Proc', $actividad->proceso);
        $this->assertSame('Sub', $actividad->subproceso);
        $this->assertSame($empresa->id_empresa, $actividad->id_empresa);
        $this->assertSame($this->fase(2)->id_fase, $actividad->id_fase);
        $this->assertSame($bloque->id_bloque, $actividad->id_bloque);
    }

    public function test_al_editar_valida_repetidas_y_permite_guardar_sin_cambios(): void
    {
        $empresa = $this->empresa();
        $this->actividad($empresa, 1, null, ['nombre' => 'Ocupada']);
        $actividad = $this->actividad($empresa, 1, null, ['nombre' => 'Actual']);
        $admin = $this->usuario();
        $url = "/actividades/{$actividad->id_actividad}";

        $this->actingAs($admin)->put($url, ['nombre' => 'Ocupada'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe una actividad igual. Si está dada de baja, puedes reactivarla.']);

        $this->actingAs($admin)->put($url, ['nombre' => 'Actual'])->assertSessionHasNoErrors();
    }

    public function test_dar_de_baja_no_borra_y_reactivar_la_recupera(): void
    {
        $actividad = $this->actividad($this->empresa());
        $admin = $this->usuario();
        $url = "/actividades/{$actividad->id_actividad}";

        $this->actingAs($admin)->from('/actividades')->patch("{$url}/baja")
            ->assertRedirect('/actividades')
            ->assertSessionHas('exito');
        $this->assertFalse($actividad->fresh()->estado);
        $this->assertSame(1, Actividad::count());

        // Se revisa la celda: el mensaje de éxito también menciona el nombre.
        $this->actingAs($admin)->get('/actividades')->assertDontSee('celda-titulo">Actividad uno', false);
        $this->actingAs($admin)->get('/actividades?ver_bajas=1')->assertSee('Actividad uno')->assertSee('Reactivar');

        $this->actingAs($admin)->from('/actividades')->patch("{$url}/reactivar")->assertSessionHas('exito');
        $this->assertTrue($actividad->fresh()->estado);
    }

    public function test_no_se_reactiva_si_su_empresa_o_bloque_estan_de_baja(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa, 'Uno', false);
        $conBloque = $this->actividad($empresa, 2, $bloque, ['nombre' => 'Con bloque', 'estado' => false]);
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$conBloque->id_actividad}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar Con bloque: su bloque está dado de baja.');
        $this->assertFalse($conBloque->fresh()->estado);

        $inactiva = $this->empresa('INACTIVA', false);
        $deInactiva = $this->actividad($inactiva, 1, null, ['nombre' => 'De inactiva', 'estado' => false]);

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$deInactiva->id_actividad}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar De inactiva: su empresa está dada de baja.');
        $this->assertFalse($deInactiva->fresh()->estado);
    }

    public function test_reactiva_una_de_onboarding_sin_bloque(): void
    {
        $actividad = $this->actividad($this->empresa(), 1, null, ['estado' => false]);

        $this->actingAs($this->usuario())->from('/actividades')->patch("/actividades/{$actividad->id_actividad}/reactivar")
            ->assertSessionHas('exito');

        $this->assertTrue($actividad->fresh()->estado);
    }

    public function test_la_baja_de_un_bloque_se_bloquea_con_actividades_activas(): void
    {
        $empresa = $this->empresa();
        $bloque = $this->bloque($empresa, 'Uno');
        $actividad = $this->actividad($empresa, 2, $bloque);
        $admin = $this->usuario();
        $url = "/bloques/{$bloque->id_bloque}/baja";

        $this->actingAs($admin)->from('/bloques')->patch($url)
            ->assertSessionHas('error', 'No se puede dar de baja Uno: todavía tiene actividades activas.');
        $this->assertTrue($bloque->fresh()->estado);

        $actividad->update(['estado' => false]);

        $this->actingAs($admin)->from('/bloques')->patch($url)->assertSessionHas('exito');
        $this->assertFalse($bloque->fresh()->estado);
    }

    public function test_la_baja_de_una_empresa_se_bloquea_con_actividades_activas(): void
    {
        $empresa = $this->empresa();
        $actividad = $this->actividad($empresa);
        $admin = $this->usuario();
        $url = "/empresas/{$empresa->id_empresa}/baja";

        $this->actingAs($admin)->from('/empresas')->patch($url)
            ->assertSessionHas('error', "No se puede dar de baja {$empresa->nombre}. Todavía tiene activos: actividades.");
        $this->assertTrue($empresa->fresh()->estado);

        $actividad->update(['estado' => false]);

        $this->actingAs($admin)->from('/empresas')->patch($url)->assertSessionHas('exito');
        $this->assertFalse($empresa->fresh()->estado);
    }

    public function test_el_menu_y_el_inicio_muestran_actividades(): void
    {
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/')
            ->assertOk()
            ->assertSee(route('actividades.index'), false);
    }
}
