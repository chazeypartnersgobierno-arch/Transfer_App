<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Proceso;
use App\Models\Empresa;
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

        return $this->asignarEmpresa(Usuario::create([
            'nombre' => 'Prueba',
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]), $empresa);
    }

    private function empresa(string $sigla = 'PROPIA', bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    private function proceso(Empresa $empresa, string $nombre = 'Tesorería', bool $estado = true): Proceso
    {
        return Proceso::create(['id_empresa' => $empresa->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    /** Toda actividad lleva proceso: si no se indica, usa el proceso "General" de la empresa. */
    private function actividad(Empresa $empresa, TipoActividad $tipo = TipoActividad::Onboarding, ?Proceso $proceso = null, array $extra = []): Actividad
    {
        $proceso ??= Proceso::firstOrCreate(['id_empresa' => $empresa->id_empresa, 'nombre' => 'General']);

        return Actividad::create([
            'id_empresa' => $empresa->id_empresa,
            'tipo' => $tipo,
            'id_proceso' => $proceso->id_proceso,
            'nombre' => 'Actividad uno',
            ...$extra,
        ]);
    }

    /** Datos válidos para crear una actividad de Transición. */
    private function datos(Empresa $empresa, Proceso $proceso, array $cambios = []): array
    {
        return [
            'id_empresa' => $empresa->id_empresa,
            'tipo' => 'Transición',
            'id_proceso' => $proceso->id_proceso,
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

    public function test_lista_muestra_datos_en_el_orden_de_carga(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa);
        $this->actividad($empresa, TipoActividad::Transicion, $proceso, ['nombre' => 'Zeta primera', 'macroproceso' => 'Finanzas']);
        $this->actividad($empresa, TipoActividad::Onboarding, $proceso, ['nombre' => 'Media segunda']);
        $this->actividad($empresa, TipoActividad::Transicion, null, ['nombre' => 'Alfa tercera']);

        $this->actingAs($this->usuario())->get('/actividades')
            ->assertOk()
            ->assertSeeInOrder(['Zeta primera', 'Media segunda', 'Alfa tercera'])
            ->assertSee('Finanzas')
            ->assertSee('Transición')
            ->assertSee('Onboarding')
            ->assertSee('>General</td>', false)
            ->assertSee('Tesorería')
            ->assertSee('Empresa PROPIA')
            ->assertSee('Nueva actividad')
            ->assertSee('3 actividades');
    }

    public function test_filtros_por_empresa_tipo_y_proceso(): void
    {
        $a = $this->empresa('AAA');
        $b = $this->empresa('BBB');
        $procesoA1 = $this->proceso($a, 'Uno');
        $procesoA2 = $this->proceso($a, 'Dos');
        $procesoB = $this->proceso($b, 'Tres');
        $this->actividad($a, TipoActividad::Onboarding, null, ['nombre' => 'En onboarding']);
        $this->actividad($a, TipoActividad::Transicion, $procesoA1, ['nombre' => 'En proceso uno']);
        $this->actividad($a, TipoActividad::Transicion, $procesoA2, ['nombre' => 'En proceso dos']);
        $this->actividad($b, TipoActividad::Transicion, $procesoB, ['nombre' => 'De la otra']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/actividades?id_empresa={$b->id_empresa}")
            ->assertSee('De la otra')->assertDontSee('En onboarding');

        $this->actingAs($admin)->get('/actividades?tipo=Onboarding')
            ->assertSee('En onboarding')->assertDontSee('En proceso uno');

        $this->actingAs($admin)->get("/actividades?id_proceso={$procesoA1->id_proceso}")
            ->assertSee('En proceso uno')->assertDontSee('En proceso dos');

        // Con otra empresa elegida, un proceso de la anterior se ignora, no deja la lista vacía.
        $this->actingAs($admin)->get("/actividades?id_empresa={$b->id_empresa}&id_proceso={$procesoA1->id_proceso}")
            ->assertSee('De la otra');
    }

    public function test_filtra_por_transicion_y_conserva_la_opcion_elegida(): void
    {
        $empresa = $this->empresa();
        $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'De una fase']);
        $this->actividad($empresa, TipoActividad::Transicion, null, ['nombre' => 'De tres fases']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?tipo='.urlencode('Transición'))
            ->assertOk()
            ->assertSee('De tres fases')
            ->assertDontSee('De una fase')
            ->assertSee('1 actividad')
            ->assertSee('<option value="Transición" selected>', false);

        // Un valor que no está en la lista se ignora.
        $this->actingAs($admin)->get('/actividades?tipo=Otro')->assertOk()->assertSee('2 actividades');
    }

    public function test_busca_en_nombre_y_procesos_con_comodines_como_texto(): void
    {
        $empresa = $this->empresa();
        $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'Primera']);
        $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'Segunda', 'macroproceso' => 'Finanzas']);
        $this->actividad($empresa, TipoActividad::Onboarding, $this->proceso($empresa, 'Pagos'), ['nombre' => 'Tercera']);
        $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'Cuarta']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?buscar=Primera')->assertSee('Primera')->assertDontSee('Segunda');
        $this->actingAs($admin)->get('/actividades?buscar=Finanzas')->assertSee('Segunda')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar=Pagos')->assertSee('Tercera')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar=%25')->assertDontSee('Primera');
        $this->actingAs($admin)->get('/actividades?buscar[]=x&tipo[]=y&id_proceso[]=z')->assertOk();
    }

    public function test_pagina_y_conserva_filtros(): void
    {
        $empresa = $this->empresa();

        for ($i = 1; $i <= 16; $i++) {
            $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => sprintf('Demo %02d', $i)]);
        }

        $this->actingAs($this->usuario())->get('/actividades?buscar=Demo')
            ->assertSee('16 actividades')
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertSee('buscar=Demo&amp;page=2', false);
    }

    public function test_crea_una_actividad_con_proceso_y_opcionales_vacios(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa);

        $this->actingAs($this->usuario())
            ->post('/actividades', $this->datos($empresa, $proceso, ['macroproceso' => '']))
            ->assertRedirect(route('actividades.index'))
            ->assertSessionHas('exito');

        $actividad = Actividad::sole();
        $this->assertSame($proceso->id_proceso, $actividad->id_proceso);
        $this->assertNull($actividad->macroproceso);
    }

    public function test_cualquier_tipo_puede_llevar_proceso(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa);
        $admin = $this->usuario();

        foreach (['Onboarding', 'Transición'] as $tipo) {
            $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso, ['tipo' => $tipo]))
                ->assertSessionHasNoErrors();

            $this->assertSame($proceso->id_proceso, Actividad::where('tipo', $tipo)->sole()->id_proceso);
        }
    }

    public function test_ningun_tipo_acepta_todos_los_procesos(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();

        foreach (['Onboarding', 'Transición'] as $tipo) {
            $this->actingAs($admin)->post('/actividades', [
                'id_empresa' => $empresa->id_empresa,
                'tipo' => $tipo,
                'id_proceso' => 'todos',
                'nombre' => 'Inducción general',
            ])->assertSessionHasErrors(['id_proceso' => 'Elige un proceso de la lista.']);
        }

        $this->assertSame(0, Actividad::count());
    }

    public function test_el_proceso_se_elige_siempre_de_forma_explicita(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();

        foreach (['Onboarding', 'Transición'] as $tipo) {
            $this->actingAs($admin)->post('/actividades', [
                'id_empresa' => $empresa->id_empresa,
                'tipo' => $tipo,
                'nombre' => 'Sin elegir',
            ])->assertSessionHasErrors(['id_proceso' => 'Elige un proceso.']);
        }

        $this->assertSame(0, Actividad::count());
    }

    public function test_el_tipo_debe_estar_en_la_lista(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso, ['tipo' => 'Otro']))
            ->assertSessionHasErrors(['tipo' => 'Elige un tipo de la lista.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso, ['tipo' => ['x']]))
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, Actividad::count());
    }

    public function test_el_proceso_debe_ser_activo_y_de_la_misma_empresa(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $admin = $this->usuario();
        $mensaje = 'El proceso no existe, está dado de baja o es de otra empresa.';

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $this->proceso($otra)))
            ->assertSessionHasErrors(['id_proceso' => $mensaje]);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $this->proceso($empresa, 'Inactivo', false)))
            ->assertSessionHasErrors(['id_proceso' => $mensaje]);

        $this->assertSame(0, Actividad::count());
    }

    public function test_validaciones_generales_en_espanol(): void
    {
        $empresa = $this->empresa();
        $inactiva = $this->empresa('INACTIVA', false);
        $proceso = $this->proceso($empresa);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', [])
            ->assertSessionHasErrors([
                'id_empresa' => 'Elige una empresa.',
                'tipo' => 'Elige un tipo.',
                'nombre' => 'Escribe el nombre de la actividad.',
            ]);

        $this->actingAs($admin)->post('/actividades', $this->datos($inactiva, $proceso))
            ->assertSessionHasErrors(['id_empresa' => 'La empresa no existe o está dada de baja.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso, ['nombre' => str_repeat('A', 501)]))
            ->assertSessionHasErrors(['nombre' => 'El nombre no puede tener más de 500 caracteres.']);

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso, ['macroproceso' => str_repeat('A', 256)]))
            ->assertSessionHasErrors(['macroproceso' => 'El macroproceso no puede tener más de 255 caracteres.']);

        $this->assertSame(0, Actividad::count());
    }

    public function test_rechaza_una_actividad_repetida_incluso_dada_de_baja(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa);
        $admin = $this->usuario();
        $mensaje = 'Ya existe una actividad igual. Si está dada de baja, puedes reactivarla.';
        $datos = $this->datos($empresa, $proceso, ['macroproceso' => 'Finanzas']);

        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors(['nombre' => $mensaje]);

        Actividad::sole()->update(['estado' => false]);
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors(['nombre' => $mensaje]);

        $this->assertSame(1, Actividad::count());
    }

    public function test_detecta_repetidas_sin_procesos(): void
    {
        $empresa = $this->empresa();
        $admin = $this->usuario();
        $datos = ['id_empresa' => $empresa->id_empresa, 'tipo' => 'Onboarding', 'id_proceso' => $this->proceso($empresa)->id_proceso, 'nombre' => 'Inducción general'];

        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasErrors('nombre');

        $this->assertSame(1, Actividad::count());
    }

    public function test_el_mismo_nombre_se_permite_si_cambia_otro_dato(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $proceso = $this->proceso($empresa, 'Uno');
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $this->datos($empresa, $proceso))->assertSessionHasNoErrors();

        $variantes = [
            'otro macroproceso' => $this->datos($empresa, $proceso, ['macroproceso' => 'Otro']),
            'otro tipo' => $this->datos($empresa, $proceso, ['tipo' => 'Onboarding']),
            'otro proceso' => $this->datos($empresa, $this->proceso($empresa, 'Dos')),
            'otra empresa' => $this->datos($otra, $this->proceso($otra, 'Uno')),
        ];

        foreach ($variantes as $datos) {
            $this->actingAs($admin)->post('/actividades', $datos)->assertSessionHasNoErrors();
        }

        $this->assertSame(5, Actividad::count());
    }

    public function test_formulario_de_chazey_trae_lo_necesario_para_filtrar(): void
    {
        $empresa = $this->empresa('AAA');
        $this->empresa('INACTIVA', false);
        $proceso = $this->proceso($empresa, 'Activo');
        $this->proceso($empresa, 'Proceso inactivo', false);

        $this->actingAs($this->usuario())->get('/actividades/crear')
            ->assertOk()
            ->assertSee('data-formulario-actividad', false)
            ->assertDontSee('Todos los procesos')
            ->assertSee('name="tipo"', false)
            ->assertDontSee('data-campo-proceso', false)
            ->assertSee('data-empresa="'.$empresa->id_empresa.'"', false)
            ->assertSee('value="'.$proceso->id_proceso.'"', false)
            ->assertSee('name="id_empresa"', false)
            ->assertDontSee('Empresa INACTIVA')
            ->assertDontSee('Proceso inactivo');
    }

    public function test_sin_empresas_activas_no_abre_el_formulario(): void
    {
        $this->actingAs($this->usuario())->get('/actividades/crear')
            ->assertRedirect(route('actividades.index'))
            ->assertSessionHas('error', 'Primero registra una empresa activa.');
    }

    public function test_consultor_ve_pero_no_cambia_nada(): void
    {
        $empresa = $this->empresa();
        $actividad = $this->actividad($empresa);
        $proceso = $this->proceso($empresa);
        $url = "/actividades/{$actividad->id_actividad}";

        $consultor = $this->usuario(RolApp::Consultor, $empresa);

        $this->actingAs($consultor)->get('/actividades')
            ->assertOk()
            ->assertSee('Actividad uno')
            ->assertDontSee('Nueva actividad')
            ->assertDontSee('Dar de baja');

        $this->actingAs($consultor)->get('/actividades/crear')->assertForbidden();
        $this->actingAs($consultor)->post('/actividades', $this->datos($empresa, $proceso))->assertForbidden();
        $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
        $this->actingAs($consultor)->put($url, ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();

        $this->assertSame(1, Actividad::count());
        $this->assertSame('Actividad uno', $actividad->fresh()->nombre);
        $this->assertTrue($actividad->fresh()->estado);
    }

    public function test_al_editar_solo_cambian_los_textos(): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $proceso = $this->proceso($empresa, 'Uno');
        $otroProceso = $this->proceso($empresa, 'Dos');
        $actividad = $this->actividad($empresa, TipoActividad::Transicion, $proceso);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/actividades/{$actividad->id_actividad}/editar")
            ->assertOk()
            ->assertSee('Empresa AAA')
            ->assertSee('Transición')
            ->assertDontSee('name="id_empresa"', false)
            ->assertDontSee('name="tipo"', false)
            ->assertDontSee('name="id_proceso"', false)
            ->assertDontSee('data-formulario-actividad', false);

        $this->actingAs($admin)->put("/actividades/{$actividad->id_actividad}", [
            'nombre' => 'Renombrada',
            'macroproceso' => 'Macro',
            'id_empresa' => $otra->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $otroProceso->id_proceso,
        ])->assertSessionHasNoErrors();

        $actividad->refresh();
        $this->assertSame('Renombrada', $actividad->nombre);
        $this->assertSame('Macro', $actividad->macroproceso);
        $this->assertSame($empresa->id_empresa, $actividad->id_empresa);
        $this->assertSame(TipoActividad::Transicion, $actividad->tipo);
        $this->assertSame($proceso->id_proceso, $actividad->id_proceso);
    }

    public function test_al_editar_valida_repetidas_y_permite_guardar_sin_cambios(): void
    {
        $empresa = $this->empresa();
        $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'Ocupada']);
        $actividad = $this->actividad($empresa, TipoActividad::Onboarding, null, ['nombre' => 'Actual']);
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

    public function test_no_se_reactiva_si_su_empresa_o_proceso_estan_de_baja(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa, 'Uno', false);
        $conProceso = $this->actividad($empresa, TipoActividad::Transicion, $proceso, ['nombre' => 'Con proceso', 'estado' => false]);
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$conProceso->id_actividad}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar Con proceso: su proceso está dado de baja.');
        $this->assertFalse($conProceso->fresh()->estado);

        $inactiva = $this->empresa('INACTIVA', false);
        $deInactiva = $this->actividad($inactiva, TipoActividad::Onboarding, null, ['nombre' => 'De inactiva', 'estado' => false]);

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$deInactiva->id_actividad}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar De inactiva: su empresa está dada de baja.');
        $this->assertFalse($deInactiva->fresh()->estado);
    }

    public function test_la_baja_de_un_proceso_se_bloquea_con_actividades_activas(): void
    {
        $empresa = $this->empresa();
        $proceso = $this->proceso($empresa, 'Uno');
        $actividad = $this->actividad($empresa, TipoActividad::Transicion, $proceso);
        $admin = $this->usuario();
        $url = "/procesos/{$proceso->id_proceso}/baja";

        $this->actingAs($admin)->from('/procesos')->patch($url)
            ->assertSessionHas('error', 'No se puede dar de baja Uno. Todavía tiene activos: actividades.');
        $this->assertTrue($proceso->fresh()->estado);

        $actividad->update(['estado' => false]);

        $this->actingAs($admin)->from('/procesos')->patch($url)->assertSessionHas('exito');
        $this->assertFalse($proceso->fresh()->estado);
    }

    public function test_la_baja_de_una_empresa_se_bloquea_con_actividades_activas(): void
    {
        $empresa = $this->empresa();
        // Proceso ya de baja, para que solo la actividad impida la baja de la empresa.
        $actividad = $this->actividad($empresa, TipoActividad::Onboarding, $this->proceso($empresa, 'Viejo', false));
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
