<?php

namespace Tests\Feature;

use App\Enums\EstadoActividad;
use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantallas de seguimiento: lista de trabajo y edición.
 * Las reglas del modelo (fecha_cierre, valores únicos) están en SeguimientoTest.
 */
class SeguimientoPantallaTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Bloque $bloque;

    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->bloque = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 1']);
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

    private function actividad(string $nombre, ?Bloque $bloque = null, TipoActividad $tipo = TipoActividad::Onboarding, bool $estado = true): Actividad
    {
        $bloque ??= $this->bloque;

        return Actividad::create(['id_empresa' => $bloque->id_empresa, 'id_bloque' => $bloque->id_bloque, 'tipo' => $tipo, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function seguimiento(Actividad $actividad, ?Grupo $grupo = null, int $orden = 1, array $extra = []): Seguimiento
    {
        return Seguimiento::create([
            'id_actividad' => $actividad->id_actividad,
            'id_grupo' => ($grupo ?? $this->grupo)->id_grupo,
            'id_fase' => Fase::where('orden', $orden)->sole()->id_fase,
            ...$extra,
        ]);
    }

    /** Datos válidos para el formulario. */
    private function datos(array $cambios = []): array
    {
        return [
            'fecha_inicio' => '2027-01-10',
            'fecha_fin' => '2027-02-10',
            'estado_actividad' => 'En curso',
            ...$cambios,
        ];
    }

    // ===== Permisos y empresa =====

    public function test_sin_sesion_redirige_a_login(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'));

        $this->get('/seguimientos')->assertRedirect(route('login'));
        $this->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")->assertRedirect(route('login'));
        $this->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos())->assertRedirect(route('login'));
    }

    public function test_el_consultor_consulta_y_edita(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Conciliar'));
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->actingAs($consultor)->get('/seguimientos')->assertOk()->assertSee('Conciliar');
        $this->actingAs($consultor)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")->assertOk()->assertSee('Guardar');
        $this->actingAs($consultor)->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos(['experto' => 'Ana']))
            ->assertRedirect(route('seguimientos.index'))
            ->assertSessionHas('exito');

        $this->assertSame('Ana', $seguimiento->fresh()->experto);
    }

    public function test_un_usuario_con_empresa_no_ve_ni_edita_seguimientos_ajenos(): void
    {
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        $bloqueAjeno = Bloque::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Ajeno']);
        $grupoAjeno = Grupo::create(['id_bloque' => $bloqueAjeno->id_bloque, 'nombre' => 'G ajeno']);
        $this->seguimiento($this->actividad('Propia'));
        $ajeno = $this->seguimiento($this->actividad('Actividad ajena', $bloqueAjeno), $grupoAjeno);
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->actingAs($consultor)->get('/seguimientos')
            ->assertSee('Propia')
            ->assertDontSee('Actividad ajena')
            ->assertDontSee('name="id_empresa"', false);

        $this->actingAs($consultor)->get("/seguimientos/{$ajeno->id_seguimiento}/editar")->assertNotFound();
        $this->actingAs($consultor)->put("/seguimientos/{$ajeno->id_seguimiento}", $this->datos())->assertNotFound();
        $this->assertNull($ajeno->fresh()->estado_actividad);

        // Chazey ve las dos y puede filtrar por empresa.
        $this->actingAs($this->usuario())->get('/seguimientos')
            ->assertSee('Propia')
            ->assertSee('Actividad ajena')
            ->assertSee('name="id_empresa"', false);
        $this->actingAs($this->usuario())->get("/seguimientos?id_empresa={$ajena->id_empresa}")
            ->assertSee('Actividad ajena')
            ->assertDontSee('>Propia', false);
    }

    // ===== Lista =====

    public function test_lista_por_fase_y_en_el_orden_de_carga(): void
    {
        $transicion = $this->actividad('Zeta transición', null, TipoActividad::Transicion);
        $onboarding = $this->actividad('Alfa onboarding');
        $this->seguimiento($transicion, null, 3);
        $this->seguimiento($transicion, null, 2);
        $this->seguimiento($onboarding, null, 1);
        Fase::where('orden', 2)->update(['nombre' => 'Fase dos']);
        Fase::where('orden', 3)->update(['nombre' => 'Fase tres']);

        $this->actingAs($this->usuario())->get('/seguimientos')
            ->assertOk()
            ->assertSeeInOrder(['Alfa onboarding', 'Zeta transición', 'Fase dos', 'Zeta transición', 'Fase tres'])
            ->assertSee('3 seguimientos');
    }

    public function test_por_defecto_oculta_los_de_grupos_o_actividades_de_baja(): void
    {
        $this->seguimiento($this->actividad('Activa'));
        $this->seguimiento($this->actividad('Actividad de baja', null, TipoActividad::Onboarding, false));
        $grupoDeBaja = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo de baja', 'estado' => false]);
        $this->seguimiento($this->actividad('Del grupo de baja'), $grupoDeBaja);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/seguimientos')
            ->assertSee('Activa')
            ->assertDontSee('Actividad de baja')
            ->assertDontSee('Del grupo de baja');

        $this->actingAs($admin)->get('/seguimientos?ver_bajas=1')
            ->assertSee('Actividad de baja')
            ->assertSee('Del grupo de baja')
            ->assertSee('Grupo o actividad de baja')
            ->assertSee('Consultar');
    }

    public function test_filtros_por_bloque_grupo_fase_y_busqueda(): void
    {
        $otroBloque = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $grupoPagos = Grupo::create(['id_bloque' => $otroBloque->id_bloque, 'nombre' => 'Grupo P']);
        $grupo2 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 2']);
        $tesoreria = $this->actividad('De tesorería', null, TipoActividad::Transicion);
        $this->seguimiento($tesoreria, null, 2);
        $this->seguimiento($tesoreria, $grupo2, 3);
        $this->seguimiento($this->actividad('De pagos', $otroBloque), $grupoPagos);
        $admin = $this->usuario();
        $fase3 = Fase::where('orden', 3)->sole();

        $this->actingAs($admin)->get("/seguimientos?id_bloque={$otroBloque->id_bloque}")
            ->assertSee('De pagos')->assertDontSee('De tesorería');

        $this->actingAs($admin)->get("/seguimientos?id_grupo={$grupo2->id_grupo}")
            ->assertSee('1 seguimiento')->assertSee('De tesorería');

        $this->actingAs($admin)->get("/seguimientos?id_fase={$fase3->id_fase}")
            ->assertSee('1 seguimiento')->assertSee($fase3->nombre);

        $this->actingAs($admin)->get('/seguimientos?buscar=pagos')
            ->assertSee('De pagos')->assertDontSee('De tesorería');

        // Con un bloque elegido, la lista de grupos solo trae los de ese bloque.
        $this->actingAs($admin)->get("/seguimientos?id_bloque={$otroBloque->id_bloque}")
            ->assertSee('Grupo P')->assertDontSee('>Grupo 2<', false);

        // Un valor que no corresponde se ignora en vez de dejar la lista vacía.
        $this->actingAs($admin)->get('/seguimientos?id_fase=999&estado=Inventado')->assertSee('3 seguimientos');
    }

    public function test_filtro_por_estado_incluye_pendientes_sin_valor(): void
    {
        $this->seguimiento($this->actividad('Sin estado'));
        $this->seguimiento($this->actividad('Marcada pendiente'), null, 1, ['estado_actividad' => EstadoActividad::Pendiente]);
        $this->seguimiento($this->actividad('En marcha'), null, 1, ['estado_actividad' => EstadoActividad::EnCurso]);
        $this->seguimiento($this->actividad('Terminada'), null, 1, ['estado_actividad' => EstadoActividad::Completada]);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/seguimientos?estado=Pendiente')
            ->assertSee('Sin estado')->assertSee('Marcada pendiente')->assertDontSee('En marcha')->assertDontSee('Terminada');

        $this->actingAs($admin)->get('/seguimientos?estado='.urlencode('En curso'))
            ->assertSee('En marcha')->assertDontSee('Terminada')->assertDontSee('Sin estado');

        $this->actingAs($admin)->get('/seguimientos?estado=Completada')
            ->assertSee('Terminada')->assertSee('1 seguimiento');
    }

    public function test_retrasado_se_calcula_con_la_fecha_fin(): void
    {
        $this->travelTo('2027-03-15 10:00');

        $retrasado = $this->seguimiento($this->actividad('Vencida'), null, 1, ['fecha_fin' => '2027-03-14', 'estado_actividad' => EstadoActividad::EnCurso]);
        $venceHoy = $this->seguimiento($this->actividad('Vence hoy'), null, 1, ['fecha_fin' => '2027-03-15']);
        $completada = $this->seguimiento($this->actividad('Completada tarde'), null, 1, ['fecha_fin' => '2027-03-01', 'estado_actividad' => EstadoActividad::Completada]);
        $sinFecha = $this->seguimiento($this->actividad('Sin fecha fin'));
        $vencidaSinEstado = $this->seguimiento($this->actividad('Vencida sin estado'), null, 1, ['fecha_fin' => '2027-01-01']);

        $this->assertTrue($retrasado->estaRetrasado());
        $this->assertTrue($vencidaSinEstado->estaRetrasado());
        $this->assertFalse($venceHoy->estaRetrasado());
        $this->assertFalse($completada->estaRetrasado());
        $this->assertFalse($sinFecha->estaRetrasado());

        // El filtro usa la misma regla.
        $this->assertEqualsCanonicalizing(
            [$retrasado->id_seguimiento, $vencidaSinEstado->id_seguimiento],
            Seguimiento::retrasados()->pluck('id_seguimiento')->all()
        );

        $this->actingAs($this->usuario())->get('/seguimientos?estado=Retrasado')
            ->assertSee('Vencida')
            ->assertSee('Vencida sin estado')
            ->assertDontSee('Vence hoy')
            ->assertDontSee('Completada tarde')
            ->assertSee('etiqueta-retrasado', false)
            ->assertSee('2 seguimientos');

        // No se guarda: al día siguiente, el que vencía hoy ya está retrasado.
        $this->travelTo('2027-03-16 08:00');
        $this->assertTrue($venceHoy->fresh()->estaRetrasado());
    }

    // ===== Editar =====

    public function test_guardar_cambia_los_datos_y_regresa_con_los_filtros(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Conciliar'));
        $admin = $this->usuario();
        $lista = route('seguimientos.index').'?estado=Pendiente';

        $this->actingAs($admin)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")
            ->assertOk()
            ->assertSee('Conciliar')
            ->assertSee('Tesorería › Grupo 1')
            ->assertSee('Se llena sola al pasar a Completada');

        $this->actingAs($admin)->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos([
            'documentacion' => 'https://sharepoint.empresa.com/doc',
            'comentarios' => 'Va bien',
            'experto' => 'Luis',
            'volver_a' => $lista,
        ]))->assertRedirect($lista)
            ->assertSessionHas('exito', 'Se guardó el seguimiento de Conciliar (Grupo 1, Onboarding).');

        $seguimiento->refresh();
        $this->assertSame('2027-01-10', $seguimiento->fecha_inicio->format('Y-m-d'));
        $this->assertSame('2027-02-10', $seguimiento->fecha_fin->format('Y-m-d'));
        $this->assertSame(EstadoActividad::EnCurso, $seguimiento->estado_actividad);
        $this->assertSame('https://sharepoint.empresa.com/doc', $seguimiento->documentacion);
        $this->assertSame('Luis', $seguimiento->experto);
        $this->assertSame('Va bien', $seguimiento->comentarios);
        $this->assertNull($seguimiento->fecha_cierre);
    }

    public function test_no_regresa_a_otra_pagina_que_no_sea_la_lista(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'));

        foreach (['https://otro-sitio.com', route('empresas.index'), route('seguimientos.index').'-falsa'] as $destino) {
            $this->actingAs($this->usuario())->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos(['volver_a' => $destino]))
                ->assertRedirect(route('seguimientos.index'));
        }
    }

    public function test_al_completar_se_llena_la_fecha_de_cierre_y_al_reabrir_se_vacia(): void
    {
        $this->travelTo('2027-03-15 10:00');
        $seguimiento = $this->seguimiento($this->actividad('Uno'));
        $admin = $this->usuario();
        $url = "/seguimientos/{$seguimiento->id_seguimiento}";

        $this->actingAs($admin)->put($url, $this->datos(['estado_actividad' => 'Completada']));
        $this->assertSame('2027-03-15', $seguimiento->fresh()->fecha_cierre->format('Y-m-d'));

        $this->actingAs($admin)->get("{$url}/editar")->assertSee('15/03/2027');

        $this->actingAs($admin)->put($url, $this->datos(['estado_actividad' => 'En curso']));
        $this->assertNull($seguimiento->fresh()->fecha_cierre);
    }

    public function test_valida_los_datos(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'));
        $admin = $this->usuario();
        $url = "/seguimientos/{$seguimiento->id_seguimiento}";

        $this->actingAs($admin)->put($url, $this->datos(['fecha_inicio' => '2027-02-10', 'fecha_fin' => '2027-02-09']))
            ->assertSessionHasErrors(['fecha_fin' => 'La fecha fin no puede ser anterior a la fecha de inicio.']);

        $this->actingAs($admin)->put($url, $this->datos(['estado_actividad' => 'Retrasado']))
            ->assertSessionHasErrors(['estado_actividad' => 'Elige un estado de la lista.']);

        $this->actingAs($admin)->put($url, $this->datos(['estado_actividad' => '']))
            ->assertSessionHasErrors(['estado_actividad' => 'Elige un estado.']);

        $this->actingAs($admin)->put($url, $this->datos(['documentacion' => str_repeat('d', 501)]))
            ->assertSessionHasErrors(['documentacion' => 'La documentación no puede tener más de 500 caracteres.']);

        $this->actingAs($admin)->put($url, $this->datos(['experto' => str_repeat('a', 256), 'fecha_inicio' => 'mañana']))
            ->assertSessionHasErrors([
                'experto' => 'Este campo no puede tener más de 255 caracteres.',
                'fecha_inicio' => 'Escribe una fecha de inicio válida.',
            ]);

        $this->assertNull($seguimiento->fresh()->estado_actividad);

        // Las fechas son opcionales, y la misma fecha de inicio y fin se permite.
        $this->actingAs($admin)->put($url, $this->datos(['fecha_inicio' => '', 'fecha_fin' => '']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put($url, $this->datos(['fecha_inicio' => '2027-02-10', 'fecha_fin' => '2027-02-10']))->assertSessionHasNoErrors();
    }

    public function test_la_documentacion_es_texto_libre(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'));

        foreach (['\\\\servidor\\carpeta\\manual.docx', 'Carpeta compartida de Tesorería', 'https://sharepoint.empresa.com/doc'] as $documentacion) {
            $this->actingAs($this->usuario())->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos(['documentacion' => $documentacion]))
                ->assertSessionHasNoErrors();

            $this->assertSame($documentacion, $seguimiento->fresh()->documentacion);
        }
    }

    public function test_en_consulta_solo_se_ofrece_abrir_una_documentacion_web(): void
    {
        $actividad = $this->actividad('De baja', null, TipoActividad::Onboarding, false);
        $web = $this->seguimiento($actividad, null, 1, ['documentacion' => 'https://sharepoint.empresa.com/doc']);
        $texto = $this->seguimiento($actividad, Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Otro']), 1, ['documentacion' => 'javascript:alert(1)']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/seguimientos/{$web->id_seguimiento}/editar")->assertSee('Abrir la documentación');
        $this->actingAs($admin)->get("/seguimientos/{$texto->id_seguimiento}/editar")->assertDontSee('Abrir la documentación');
    }

    public function test_actividad_grupo_y_fase_no_se_pueden_cambiar(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'));
        $otra = $this->actividad('Otra');
        $otroGrupo = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Otro']);

        $this->actingAs($this->usuario())->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos([
            'id_actividad' => $otra->id_actividad,
            'id_grupo' => $otroGrupo->id_grupo,
            'id_fase' => Fase::where('orden', 2)->sole()->id_fase,
            'fecha_cierre' => '2000-01-01',
        ]))->assertSessionHasNoErrors();

        $seguimiento->refresh();
        $this->assertSame($this->grupo->id_grupo, $seguimiento->id_grupo);
        $this->assertSame('Uno', $seguimiento->actividad->nombre);
        $this->assertSame(1, $seguimiento->fase->orden);
        $this->assertNull($seguimiento->fecha_cierre);
    }

    public function test_los_de_baja_solo_se_consultan(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('De baja', null, TipoActividad::Onboarding, false), null, 1, ['experto' => 'Original']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")
            ->assertOk()
            ->assertSee('Consultar seguimiento')
            ->assertSee('solo se puede consultar')
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('>Guardar<', false);

        $this->actingAs($admin)->put("/seguimientos/{$seguimiento->id_seguimiento}", $this->datos(['experto' => 'Cambio']))
            ->assertRedirect(route('seguimientos.editar', $seguimiento))
            ->assertSessionHas('error', 'Este seguimiento es de un grupo o una actividad dados de baja: solo se puede consultar.');

        $this->assertSame('Original', $seguimiento->fresh()->experto);
    }

    public function test_el_menu_y_el_inicio_muestran_seguimientos(): void
    {
        foreach ([$this->usuario(), $this->usuario(RolApp::Consultor, $this->empresa)] as $usuario) {
            $this->actingAs($usuario)->get('/')
                ->assertOk()
                ->assertSee(route('seguimientos.index'), false)
                ->assertSee('Seguimientos');
        }
    }
}
