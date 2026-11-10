<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Models\Usuario;
use App\Services\GeneradorDeSeguimientos;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GrupoTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Proceso $proceso;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->empresa = $this->crearEmpresa('PROPIA');
        $this->proceso = $this->crearProceso($this->empresa, 'Tesorería');
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

    private function crearEmpresa(string $sigla, bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    private function crearProceso(Empresa $empresa, string $nombre, bool $estado = true): Proceso
    {
        return Proceso::create(['id_empresa' => $empresa->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function grupo(?Proceso $proceso = null, string $nombre = 'Grupo uno', bool $estado = true): Grupo
    {
        return Grupo::create(['id_proceso' => ($proceso ?? $this->proceso)->id_proceso, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function fase(int $orden): Fase
    {
        return Fase::where('orden', $orden)->sole();
    }

    /** Actividad de un proceso. Por defecto es de Onboarding, que genera 1 seguimiento por grupo. */
    private function deProceso(string $nombre = 'De proceso', ?Proceso $proceso = null, bool $estado = true, TipoActividad $tipo = TipoActividad::Onboarding): Actividad
    {
        $proceso ??= $this->proceso;

        return Actividad::create([
            'id_empresa' => $proceso->id_empresa,
            'tipo' => $tipo,
            'id_proceso' => $proceso->id_proceso,
            'nombre' => $nombre,
            'estado' => $estado,
        ]);
    }

    private function seguimiento(Grupo $grupo, Actividad $actividad, array $extra = []): Seguimiento
    {
        return Seguimiento::create(['id_grupo' => $grupo->id_grupo, 'id_actividad' => $actividad->id_actividad, 'id_fase' => $this->fase(1)->id_fase, ...$extra]);
    }

    /** Ids de las actividades que tiene un grupo. */
    private function actividadesDe(Grupo $grupo): array
    {
        return $grupo->seguimientos()->orderBy('id_actividad')->pluck('id_actividad')->all();
    }

    // ===== Pantallas y permisos =====

    public function test_sin_sesion_redirige_a_login(): void
    {
        $grupo = $this->grupo();

        $this->get('/grupos')->assertRedirect(route('login'));
        $this->get("/grupos/{$grupo->id_grupo}/editar")->assertRedirect(route('login'));
    }

    public function test_lista_y_filtros(): void
    {
        $otraEmpresa = $this->crearEmpresa('OTRA');
        $otroProceso = $this->crearProceso($otraEmpresa, 'Compras');
        $mismoEmpresa = $this->crearProceso($this->empresa, 'Pagos');
        $this->grupo($this->proceso, 'Grupo A');
        $this->grupo($mismoEmpresa, 'Grupo B');
        $this->grupo($otroProceso, 'Grupo C');
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos')
            ->assertOk()
            ->assertSee('Grupo A')
            ->assertSee('Grupo C')
            ->assertSee('Tesorería')
            ->assertSee('Empresa OTRA')
            ->assertSee('Nuevo grupo')
            ->assertSee('3 grupos');

        $this->actingAs($admin)->get("/grupos?id_empresa={$otraEmpresa->id_empresa}")
            ->assertSee('Grupo C')->assertDontSee('Grupo A');

        $this->actingAs($admin)->get("/grupos?id_proceso={$mismoEmpresa->id_proceso}")
            ->assertSee('Grupo B')->assertDontSee('Grupo A');

        $this->actingAs($admin)->get('/grupos?buscar=Grupo+A')
            ->assertSee('Grupo A')->assertDontSee('Grupo B');

        // Con otra empresa elegida, un proceso de la anterior se ignora.
        $this->actingAs($admin)->get("/grupos?id_empresa={$otraEmpresa->id_empresa}&id_proceso={$mismoEmpresa->id_proceso}")
            ->assertSee('Grupo C');

        $this->actingAs($admin)->get('/grupos?buscar[]=x&id_proceso[]=y')->assertOk();
        $this->actingAs($admin)->get('/grupos?buscar=%25')->assertDontSee('Grupo A');
    }

    public function test_pagina_y_conserva_la_busqueda(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->grupo($this->proceso, sprintf('Demo %02d', $i));
        }

        $this->actingAs($this->usuario())->get('/grupos?buscar=Demo')
            ->assertSee('16 grupos')
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertSee('buscar=Demo&amp;page=2', false);
    }

    public function test_el_formulario_ofrece_solo_procesos_activos_con_su_sigla(): void
    {
        $this->crearProceso($this->empresa, 'Proceso inactivo', false);
        $inactiva = $this->crearEmpresa('INACTIVA', false);
        $this->crearProceso($inactiva, 'De empresa inactiva');

        $this->actingAs($this->usuario())->get('/grupos/crear')
            ->assertOk()
            ->assertSee('Tesorería (PROPIA)')
            ->assertDontSee('Proceso inactivo')
            ->assertDontSee('De empresa inactiva');
    }

    public function test_sin_procesos_activos_no_abre_el_formulario(): void
    {
        $this->proceso->update(['estado' => false]);

        $this->actingAs($this->usuario())->get('/grupos/crear')
            ->assertRedirect(route('grupos.index'))
            ->assertSessionHas('error', 'Primero registra un proceso activo.');
    }

    public function test_validaciones_con_mensajes_en_espanol(): void
    {
        $this->grupo($this->proceso, 'Repetido');
        $inactivo = $this->crearProceso($this->empresa, 'Inactivo', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/grupos', [])
            ->assertSessionHasErrors(['id_proceso' => 'Elige un proceso.', 'nombre' => 'Escribe el nombre del grupo.']);

        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Repetido'])
            ->assertSessionHasErrors(['nombre' => 'Este proceso ya tiene un grupo con este nombre.']);

        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $inactivo->id_proceso, 'nombre' => 'Otro'])
            ->assertSessionHasErrors(['id_proceso' => 'El proceso no existe, está dado de baja o es de otra empresa.']);

        $this->actingAs($admin)->post('/grupos', ['id_proceso' => ['x'], 'nombre' => 'Otro'])
            ->assertSessionHasErrors('id_proceso');

        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => str_repeat('A', 256)])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, Grupo::count());
    }

    public function test_otro_proceso_puede_usar_el_mismo_nombre(): void
    {
        $this->grupo($this->proceso, 'Compartido');
        $otro = $this->crearProceso($this->empresa, 'Otro');

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $otro->id_proceso, 'nombre' => 'Compartido'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Grupo::where('nombre', 'Compartido')->count());
    }

    public function test_consultor_ve_pero_no_cambia_nada(): void
    {
        $grupo = $this->grupo();
        $url = "/grupos/{$grupo->id_grupo}";

        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->actingAs($consultor)->get('/grupos')
            ->assertOk()
            ->assertSee('Grupo uno')
            ->assertDontSee('Nuevo grupo')
            ->assertDontSee('Dar de baja');

        $this->actingAs($consultor)->get('/grupos/crear')->assertForbidden();
        $this->actingAs($consultor)->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
        $this->actingAs($consultor)->put($url, ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();

        $this->assertSame(1, Grupo::count());
        $this->assertSame('Grupo uno', $grupo->fresh()->nombre);
        $this->assertTrue($grupo->fresh()->estado);
    }

    public function test_al_editar_solo_cambia_el_nombre(): void
    {
        $grupo = $this->grupo();
        $otro = $this->crearProceso($this->empresa, 'Otro');
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/grupos/{$grupo->id_grupo}/editar")
            ->assertOk()
            ->assertSee('Tesorería')
            ->assertDontSee('name="id_proceso"', false);

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Renombrado', 'id_proceso' => $otro->id_proceso])
            ->assertSessionHasNoErrors();

        $grupo->refresh();
        $this->assertSame('Renombrado', $grupo->nombre);
        $this->assertSame($this->proceso->id_proceso, $grupo->id_proceso);
    }

    public function test_al_editar_valida_el_nombre_dentro_de_su_proceso(): void
    {
        $this->grupo($this->proceso, 'Ocupado');
        $grupo = $this->grupo($this->proceso, 'Actual');
        $admin = $this->usuario();

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Ocupado'])
            ->assertSessionHasErrors(['nombre' => 'Este proceso ya tiene un grupo con este nombre.']);

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Actual'])->assertSessionHasNoErrors();
    }

    // ===== Generación al crear un grupo =====

    public function test_crear_un_grupo_genera_solo_las_actividades_activas_de_su_proceso(): void
    {
        $general = $this->deProceso('General');
        $delProceso = $this->deProceso();

        // Ninguna de estas le corresponde.
        $this->deProceso('De otro proceso', $this->crearProceso($this->empresa, 'Otro'));
        $this->deProceso('Proceso ajeno', $this->crearProceso($this->crearEmpresa('OTRA'), 'Ajeno'));
        $this->deProceso('De proceso inactiva', null, false);

        $this->actingAs($this->usuario())
            ->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])
            ->assertRedirect(route('grupos.index'))
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 2 seguimientos.');

        $grupo = Grupo::where('nombre', 'Nuevo')->sole();
        $this->assertTrue($grupo->estado);
        $this->assertSame([$general->id_actividad, $delProceso->id_actividad], $this->actividadesDe($grupo));
    }

    public function test_los_seguimientos_generados_nacen_vacios(): void
    {
        $this->deProceso('General');

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo']);

        $seguimiento = Seguimiento::sole();
        $this->assertTrue($seguimiento->estado);
        $this->assertNull($seguimiento->estado_actividad);
        $this->assertNull($seguimiento->fecha_inicio);
        $this->assertNull($seguimiento->responsable);
    }

    public function test_crear_un_grupo_sin_actividades_lo_indica(): void
    {
        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Solo'])
            ->assertSessionHas('exito', 'Se creó el grupo Solo sin seguimientos todavía.');

        $this->assertSame(0, Seguimiento::count());
    }

    public function test_si_falla_la_generacion_tampoco_queda_el_grupo(): void
    {
        $this->deProceso('General');

        $this->app->instance(GeneradorDeSeguimientos::class, new class extends GeneradorDeSeguimientos
        {
            public function generarParaGrupo(Grupo $grupo): int
            {
                throw new RuntimeException('Fallo simulado');
            }
        });

        $this->actingAs($this->usuario())
            ->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Fallido'])
            ->assertServerError();

        $this->assertFalse(Grupo::where('nombre', 'Fallido')->exists());
        $this->assertSame(0, Seguimiento::count());
    }

    // ===== Generación al crear una actividad =====

    public function test_crear_una_actividad_avisa_antes_y_no_guarda_sin_confirmar(): void
    {
        $this->grupo($this->proceso, 'Grupo A');
        $this->grupo($this->proceso, 'Grupo B');
        $datos = [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Nueva actividad de prueba',
            'macroproceso' => 'Pagos',
        ];
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $datos)
            ->assertOk()
            ->assertSee('Se generarán 2 seguimientos')
            ->assertSee('Confirmar y guardar')
            ->assertSee('Nueva actividad de prueba')
            ->assertSee('value="Pagos"', false);

        $this->assertSame(0, Actividad::count());
        $this->assertSame(0, Seguimiento::count());

        $this->actingAs($admin)->post('/actividades', [...$datos, 'accion' => 'confirmar'])
            ->assertRedirect(route('actividades.index'))
            ->assertSessionHas('exito', 'Se creó la actividad Nueva actividad de prueba. Se generaron 2 seguimientos.');

        $this->assertSame('Pagos', Actividad::sole()->macroproceso);
        $this->assertSame(2, Seguimiento::count());
    }

    public function test_sin_grupos_a_los_que_aplique_no_pide_confirmacion(): void
    {
        // Un grupo de otro proceso y uno de baja no reciben la actividad.
        $this->grupo($this->crearProceso($this->empresa, 'Otro'));
        $this->grupo($this->proceso, 'De baja', false);

        $this->actingAs($this->usuario())->post('/actividades', [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Directa',
        ])->assertRedirect(route('actividades.index'))->assertSessionHas('exito', 'Se creó la actividad Directa.');

        $this->assertSame(0, Seguimiento::count());
    }

    public function test_corregir_vuelve_al_formulario_con_los_datos(): void
    {
        $this->grupo();

        $this->actingAs($this->usuario())->post('/actividades', [
            'accion' => 'corregir',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'A corregir',
        ])->assertRedirect(route('actividades.crear'))->assertSessionHasInput('nombre', 'A corregir');

        $this->assertSame(0, Actividad::count());
    }

    public function test_confirmar_vuelve_a_validar(): void
    {
        $this->grupo();
        $this->deProceso('Ya existe');

        $this->actingAs($this->usuario())->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Ya existe',
        ])->assertSessionHasErrors('nombre');

        $this->assertSame(1, Actividad::count());
    }

    public function test_ya_no_existe_la_opcion_todos_los_procesos(): void
    {
        $this->grupo($this->proceso, 'En un proceso');

        foreach (['todos', ''] as $valor) {
            $this->actingAs($this->usuario())->post('/actividades', [
                'accion' => 'confirmar',
                'id_empresa' => $this->empresa->id_empresa,
                'tipo' => 'Onboarding',
                'id_proceso' => $valor,
                'nombre' => 'Inducción',
            ])->assertSessionHasErrors('id_proceso');
        }

        $this->assertSame(0, Actividad::count());
        $this->assertSame(0, Seguimiento::count());
    }

    public function test_actividad_con_proceso_solo_llega_a_los_grupos_de_ese_proceso(): void
    {
        $propio = $this->grupo($this->proceso, 'Propio');
        $otro = $this->grupo($this->crearProceso($this->empresa, 'Otro'), 'De otro proceso');

        $this->actingAs($this->usuario())->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Solo este proceso',
        ])->assertSessionHas('exito');

        $this->assertSame(1, $propio->seguimientos()->count());
        $this->assertSame(0, $otro->seguimientos()->count());
    }

    // ===== Generación por fase según el tipo =====

    public function test_onboarding_genera_un_seguimiento_en_la_fase_de_orden_1(): void
    {
        $this->deProceso('Solo una fase');

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 1 seguimiento.');

        $this->assertSame($this->fase(1)->id_fase, Seguimiento::sole()->id_fase);
    }

    public function test_transicion_genera_tres_seguimientos_uno_por_fase(): void
    {
        $actividad = $this->deProceso('Tres fases', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 3 seguimientos.');

        $this->assertSame(
            [$this->fase(2)->id_fase, $this->fase(3)->id_fase, $this->fase(4)->id_fase],
            Seguimiento::where('id_actividad', $actividad->id_actividad)->orderBy('id_fase')->pluck('id_fase')->all()
        );
    }

    public function test_un_proceso_con_ambos_tipos_suma_sus_fases(): void
    {
        $this->deProceso('Onboarding uno');
        $this->deProceso('Onboarding dos');
        $this->deProceso('Transición uno', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 5 seguimientos.');
    }

    public function test_el_onboarding_de_un_proceso_no_llega_a_otro_proceso(): void
    {
        $this->deProceso('Solo Tesorería');
        $otro = $this->crearProceso($this->empresa, 'Desempeño');
        $this->deProceso('Solo Desempeño', $otro);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $otro->id_proceso, 'nombre' => 'De Desempeño']);

        $grupo = Grupo::where('nombre', 'De Desempeño')->sole();
        $this->assertSame(['Solo Desempeño'], $grupo->seguimientos()->with('actividad')->get()->pluck('actividad.nombre')->all());
    }

    public function test_crear_una_transicion_avisa_el_total_real_de_seguimientos(): void
    {
        $this->grupo($this->proceso, 'Grupo A');
        $this->grupo($this->proceso, 'Grupo B');
        $datos = [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Transición',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Tres fases',
        ];
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $datos)
            ->assertOk()
            ->assertSee('Se generarán 6 seguimientos en el proceso Tesorería.');

        $this->actingAs($admin)->post('/actividades', [...$datos, 'accion' => 'confirmar'])
            ->assertSessionHas('exito', 'Se creó la actividad Tres fases. Se generaron 6 seguimientos.');

        $this->assertSame(6, Seguimiento::count());
    }

    public function test_confirmar_conserva_el_proceso_elegido(): void
    {
        $this->grupo();

        $this->actingAs($this->usuario())->post('/actividades', [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'General',
        ])->assertOk()
            ->assertSee('en el proceso Tesorería')
            ->assertSee('name="id_proceso" value="'.$this->proceso->id_proceso.'"', false);
    }

    public function test_reactivar_una_transicion_incompleta_solo_genera_las_fases_que_faltan(): void
    {
        $actividad = $this->deProceso('Tres fases', null, false, TipoActividad::Transicion);
        $grupo = $this->grupo();
        $this->seguimiento($grupo, $actividad, ['id_fase' => $this->fase(2)->id_fase, 'comentarios' => 'Se conserva']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?ver_bajas=1')->assertSee('Se generarán 2 seguimientos');

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$actividad->id_actividad}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó la actividad Tres fases. Se generaron 2 seguimientos.');

        $this->assertSame(3, $grupo->seguimientos()->count());
        $this->assertSame('Se conserva', $grupo->seguimientos()->where('id_fase', $this->fase(2)->id_fase)->sole()->comentarios);
    }

    public function test_generar_funciona_aunque_se_renombren_las_fases(): void
    {
        $this->fase(2)->update(['nombre' => 'Nombre nuevo']);
        $this->deProceso('Tres fases', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 3 seguimientos.');
    }

    // ===== Baja: los seguimientos no la bloquean y quedan intactos =====

    public function test_la_baja_avisa_cuantos_seguimientos_tiene_el_grupo_y_los_conserva(): void
    {
        $grupo = $this->grupo();
        $actividad = $this->deProceso('General');
        $otra = $this->deProceso();
        $this->seguimiento($grupo, $actividad, ['comentarios' => 'Se conserva']);
        $this->seguimiento($grupo, $otra);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos')
            ->assertSee('Tiene 2 seguimientos; se conservan.');

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/baja")
            ->assertRedirect('/grupos')
            ->assertSessionHas('exito', 'Se dio de baja el grupo Grupo uno. Sus seguimientos quedaron intactos.');

        $this->assertFalse($grupo->fresh()->estado);
        $this->assertSame(1, Grupo::count());
        $this->assertSame(2, Seguimiento::count());
        $this->assertSame('Se conserva', Seguimiento::where('id_actividad', $actividad->id_actividad)->sole()->comentarios);
        $this->assertTrue(Seguimiento::where('id_actividad', $actividad->id_actividad)->sole()->estado);
    }

    public function test_la_baja_avisa_cuantos_seguimientos_tiene_la_actividad_y_los_conserva(): void
    {
        $actividad = $this->deProceso('General');
        $this->seguimiento($this->grupo($this->proceso, 'A'), $actividad, ['comentarios' => 'Se conserva']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades')
            ->assertSee('Tiene 1 seguimiento; se conserva.');

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$actividad->id_actividad}/baja")
            ->assertSessionHas('exito', 'Se dio de baja la actividad General. Sus seguimientos quedaron intactos.');

        $this->assertFalse($actividad->fresh()->estado);
        $this->assertSame('Se conserva', Seguimiento::sole()->comentarios);
    }

    public function test_la_baja_de_un_grupo_sin_seguimientos_lo_indica(): void
    {
        $this->grupo();

        $this->actingAs($this->usuario())->get('/grupos')
            ->assertSee('No tiene seguimientos.')
            ->assertDontSee('Tiene 0');
    }

    public function test_los_dados_de_baja_se_ocultan_por_defecto(): void
    {
        $this->grupo($this->proceso, 'Activo');
        $this->grupo($this->proceso, 'Inactivo', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos')->assertSee('Activo')->assertDontSee('celda-titulo">Inactivo', false);
        $this->actingAs($admin)->get('/grupos?ver_bajas=1')->assertSee('celda-titulo">Inactivo', false);
    }

    public function test_los_seguimientos_de_baja_no_cuentan_en_el_trabajo(): void
    {
        $grupo = $this->grupo($this->proceso, 'A');
        $otroGrupo = $this->grupo($this->proceso, 'B');
        $actividad = $this->deProceso('Uno');
        $otraActividad = $this->deProceso('Dos');
        $seguimiento = $this->seguimiento($grupo, $actividad);
        $delOtroGrupo = $this->seguimiento($otroGrupo, $actividad);
        $deLaOtraActividad = $this->seguimiento($grupo, $otraActividad);

        $this->assertSame(3, Seguimiento::enTrabajo()->count());

        $otroGrupo->update(['estado' => false]);
        $this->assertSame([$seguimiento->getKey(), $deLaOtraActividad->getKey()], Seguimiento::enTrabajo()->orderBy('id_seguimiento')->pluck('id_seguimiento')->all());

        $otroGrupo->update(['estado' => true]);
        $otraActividad->update(['estado' => false]);
        $this->assertSame([$seguimiento->getKey(), $delOtroGrupo->getKey()], Seguimiento::enTrabajo()->orderBy('id_seguimiento')->pluck('id_seguimiento')->all());

        // Siguen guardados y se pueden consultar sin ese filtro.
        $this->assertSame(3, Seguimiento::count());
    }

    // ===== Reactivar: genera lo que falte sin tocar lo existente =====

    public function test_reactivar_un_grupo_avisa_y_genera_solo_lo_que_falta(): void
    {
        $existente = $this->deProceso('Existente');
        $grupo = $this->grupo($this->proceso, 'Grupo uno', false);
        $this->seguimiento($grupo, $existente, ['comentarios' => 'Se conserva']);
        $nueva = $this->deProceso('Creada durante la baja');
        $this->deProceso('Inactiva', null, false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos?ver_bajas=1')
            ->assertSee('Se generará 1 seguimiento.', false);

        $this->assertSame(1, Seguimiento::count());

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó el grupo Grupo uno. Se generó 1 seguimiento.');

        $this->assertTrue($grupo->fresh()->estado);
        $this->assertSame([$existente->id_actividad, $nueva->id_actividad], $this->actividadesDe($grupo));
        $this->assertSame('Se conserva', Seguimiento::where('id_actividad', $existente->id_actividad)->sole()->comentarios);
    }

    public function test_reactivar_un_grupo_completo_no_genera_nada(): void
    {
        $actividad = $this->deProceso('General');
        $grupo = $this->grupo($this->proceso, 'Grupo uno', false);
        $this->seguimiento($grupo, $actividad);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos?ver_bajas=1')->assertSee('No hace falta generar seguimientos nuevos.');

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó el grupo Grupo uno.');

        $this->assertSame(1, Seguimiento::count());
    }

    public function test_reactivar_una_actividad_avisa_y_genera_solo_para_grupos_activos(): void
    {
        $actividad = $this->deProceso('Actividad', null, false);
        $conSeguimiento = $this->grupo($this->proceso, 'Ya la tiene');
        $this->seguimiento($conSeguimiento, $actividad, ['comentarios' => 'Se conserva']);
        $sinSeguimiento = $this->grupo($this->proceso, 'Creado durante la baja');
        $inactivo = $this->grupo($this->proceso, 'De baja', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?ver_bajas=1')
            ->assertSee('Se generará 1 seguimiento.', false);

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$actividad->id_actividad}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó la actividad Actividad. Se generó 1 seguimiento.');

        $this->assertTrue($actividad->fresh()->estado);
        $this->assertSame(1, $sinSeguimiento->seguimientos()->count());
        $this->assertSame(0, $inactivo->seguimientos()->count());
        $this->assertSame('Se conserva', $conSeguimiento->seguimientos()->sole()->comentarios);
    }

    public function test_reactivar_dos_veces_no_duplica(): void
    {
        $this->deProceso('General');
        $grupo = $this->grupo($this->proceso, 'Grupo uno', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar");
        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar");

        $this->assertSame(1, Seguimiento::count());
    }

    public function test_el_generador_ignora_grupos_y_actividades_de_baja(): void
    {
        $generador = new GeneradorDeSeguimientos;
        $actividadActiva = $this->deProceso('Activa');
        $actividadInactiva = $this->deProceso('Inactiva', null, false);
        $grupoActivo = $this->grupo($this->proceso, 'Activo');
        $grupoInactivo = $this->grupo($this->proceso, 'Inactivo', false);

        $this->assertSame(0, $generador->generarParaGrupo($grupoInactivo));
        $this->assertSame(0, $generador->generarParaActividad($actividadInactiva));
        $this->assertSame(0, Seguimiento::count());

        $this->assertSame(1, $generador->generarParaGrupo($grupoActivo->fresh()));
        $this->assertSame([$actividadActiva->id_actividad], $this->actividadesDe($grupoActivo));
    }

    public function test_no_se_reactiva_si_su_proceso_esta_de_baja(): void
    {
        $this->deProceso('General');
        $inactivo = $this->crearProceso($this->empresa, 'Inactivo', false);
        $grupo = $this->grupo($inactivo, 'Grupo uno', false);

        $this->actingAs($this->usuario())->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar Grupo uno: su proceso está dado de baja.');

        $this->assertFalse($grupo->fresh()->estado);
        $this->assertSame(0, Seguimiento::count());
    }

    public function test_la_baja_de_un_proceso_se_bloquea_con_grupos_activos(): void
    {
        $grupo = $this->grupo();
        $admin = $this->usuario();
        $url = "/procesos/{$this->proceso->id_proceso}/baja";

        $this->actingAs($admin)->from('/procesos')->patch($url)
            ->assertSessionHas('error', 'No se puede dar de baja Tesorería. Todavía tiene activos: grupos.');
        $this->assertTrue($this->proceso->fresh()->estado);

        $grupo->update(['estado' => false]);

        $this->actingAs($admin)->from('/procesos')->patch($url)->assertSessionHas('exito');
        $this->assertFalse($this->proceso->fresh()->estado);
    }

    public function test_el_menu_y_el_inicio_muestran_grupos(): void
    {
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/')
            ->assertOk()
            ->assertSee(route('grupos.index'), false);
    }
}
