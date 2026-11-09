<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Bloque;
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

    private Bloque $bloque;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->empresa = $this->crearEmpresa('PROPIA');
        $this->bloque = $this->crearBloque($this->empresa, 'Tesorería');
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

    private function crearEmpresa(string $sigla, bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    private function crearBloque(Empresa $empresa, string $nombre, bool $estado = true): Bloque
    {
        return Bloque::create(['id_empresa' => $empresa->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function grupo(?Bloque $bloque = null, string $nombre = 'Grupo uno', bool $estado = true): Grupo
    {
        return Grupo::create(['id_bloque' => ($bloque ?? $this->bloque)->id_bloque, 'nombre' => $nombre, 'estado' => $estado]);
    }

    private function fase(int $orden): Fase
    {
        return Fase::where('orden', $orden)->sole();
    }

    /** Actividad de un bloque. Por defecto es de Onboarding, que genera 1 seguimiento por grupo. */
    private function deBloque(string $nombre = 'De bloque', ?Bloque $bloque = null, bool $estado = true, TipoActividad $tipo = TipoActividad::Onboarding): Actividad
    {
        $bloque ??= $this->bloque;

        return Actividad::create([
            'id_empresa' => $bloque->id_empresa,
            'tipo' => $tipo,
            'id_bloque' => $bloque->id_bloque,
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
        $otroBloque = $this->crearBloque($otraEmpresa, 'Compras');
        $mismoEmpresa = $this->crearBloque($this->empresa, 'Pagos');
        $this->grupo($this->bloque, 'Grupo A');
        $this->grupo($mismoEmpresa, 'Grupo B');
        $this->grupo($otroBloque, 'Grupo C');
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

        $this->actingAs($admin)->get("/grupos?id_bloque={$mismoEmpresa->id_bloque}")
            ->assertSee('Grupo B')->assertDontSee('Grupo A');

        $this->actingAs($admin)->get('/grupos?buscar=Grupo+A')
            ->assertSee('Grupo A')->assertDontSee('Grupo B');

        // Con otra empresa elegida, un bloque de la anterior se ignora.
        $this->actingAs($admin)->get("/grupos?id_empresa={$otraEmpresa->id_empresa}&id_bloque={$mismoEmpresa->id_bloque}")
            ->assertSee('Grupo C');

        $this->actingAs($admin)->get('/grupos?buscar[]=x&id_bloque[]=y')->assertOk();
        $this->actingAs($admin)->get('/grupos?buscar=%25')->assertDontSee('Grupo A');
    }

    public function test_pagina_y_conserva_la_busqueda(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->grupo($this->bloque, sprintf('Demo %02d', $i));
        }

        $this->actingAs($this->usuario())->get('/grupos?buscar=Demo')
            ->assertSee('16 grupos')
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertSee('buscar=Demo&amp;page=2', false);
    }

    public function test_el_formulario_ofrece_solo_bloques_activos_con_su_sigla(): void
    {
        $this->crearBloque($this->empresa, 'Bloque inactivo', false);
        $inactiva = $this->crearEmpresa('INACTIVA', false);
        $this->crearBloque($inactiva, 'De empresa inactiva');

        $this->actingAs($this->usuario())->get('/grupos/crear')
            ->assertOk()
            ->assertSee('Tesorería (PROPIA)')
            ->assertDontSee('Bloque inactivo')
            ->assertDontSee('De empresa inactiva');
    }

    public function test_sin_bloques_activos_no_abre_el_formulario(): void
    {
        $this->bloque->update(['estado' => false]);

        $this->actingAs($this->usuario())->get('/grupos/crear')
            ->assertRedirect(route('grupos.index'))
            ->assertSessionHas('error', 'Primero registra un bloque activo.');
    }

    public function test_validaciones_con_mensajes_en_espanol(): void
    {
        $this->grupo($this->bloque, 'Repetido');
        $inactivo = $this->crearBloque($this->empresa, 'Inactivo', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/grupos', [])
            ->assertSessionHasErrors(['id_bloque' => 'Elige un bloque.', 'nombre' => 'Escribe el nombre del grupo.']);

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Repetido'])
            ->assertSessionHasErrors(['nombre' => 'Este bloque ya tiene un grupo con este nombre.']);

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $inactivo->id_bloque, 'nombre' => 'Otro'])
            ->assertSessionHasErrors(['id_bloque' => 'El bloque no existe, está dado de baja o es de otra empresa.']);

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => ['x'], 'nombre' => 'Otro'])
            ->assertSessionHasErrors('id_bloque');

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => str_repeat('A', 256)])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, Grupo::count());
    }

    public function test_otro_bloque_puede_usar_el_mismo_nombre(): void
    {
        $this->grupo($this->bloque, 'Compartido');
        $otro = $this->crearBloque($this->empresa, 'Otro');

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $otro->id_bloque, 'nombre' => 'Compartido'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Grupo::where('nombre', 'Compartido')->count());
    }

    public function test_admin_de_empresa_solo_ve_y_crea_en_la_suya(): void
    {
        $ajena = $this->crearEmpresa('AJENA');
        $bloqueAjeno = $this->crearBloque($ajena, 'Bloque ajeno');
        $this->grupo($this->bloque, 'Grupo propio');
        $grupoAjeno = $this->grupo($bloqueAjeno, 'Grupo ajeno');
        $admin = $this->usuario(RolApp::Admin, $this->empresa);

        $this->actingAs($admin)->get('/grupos')
            ->assertSee('Grupo propio')
            ->assertSee('Tesorería')
            ->assertDontSee('Grupo ajeno')
            ->assertDontSee('Bloque ajeno')
            ->assertDontSee('Todas las empresas');

        $this->actingAs($admin)->get("/grupos?id_empresa={$ajena->id_empresa}")->assertDontSee('Grupo ajeno');

        $this->actingAs($admin)->get('/grupos/crear')
            ->assertOk()
            ->assertSee('Tesorería')
            ->assertDontSee('(PROPIA)')
            ->assertDontSee('Bloque ajeno');

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $bloqueAjeno->id_bloque, 'nombre' => 'Intruso'])
            ->assertSessionHasErrors('id_bloque');
        $this->assertFalse(Grupo::where('nombre', 'Intruso')->exists());

        $url = "/grupos/{$grupoAjeno->id_grupo}";
        $this->actingAs($admin)->get("{$url}/editar")->assertNotFound();
        $this->actingAs($admin)->put($url, ['nombre' => 'X'])->assertNotFound();
        $this->actingAs($admin)->patch("{$url}/baja")->assertNotFound();
        $this->actingAs($admin)->patch("{$url}/reactivar")->assertNotFound();
        $this->assertSame('Grupo ajeno', $grupoAjeno->fresh()->nombre);
        $this->assertTrue($grupoAjeno->fresh()->estado);
    }

    public function test_consultor_ve_pero_no_cambia_nada(): void
    {
        $grupo = $this->grupo();
        $url = "/grupos/{$grupo->id_grupo}";

        foreach ([$this->usuario(RolApp::Consultor), $this->usuario(RolApp::Consultor, $this->empresa)] as $consultor) {
            $this->actingAs($consultor)->get('/grupos')
                ->assertOk()
                ->assertSee('Grupo uno')
                ->assertDontSee('Nuevo grupo')
                ->assertDontSee('Dar de baja');

            $this->actingAs($consultor)->get('/grupos/crear')->assertForbidden();
            $this->actingAs($consultor)->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'X'])->assertForbidden();
            $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
            $this->actingAs($consultor)->put($url, ['nombre' => 'X'])->assertForbidden();
            $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
            $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();
        }

        $this->assertSame(1, Grupo::count());
        $this->assertSame('Grupo uno', $grupo->fresh()->nombre);
        $this->assertTrue($grupo->fresh()->estado);
    }

    public function test_al_editar_solo_cambia_el_nombre(): void
    {
        $grupo = $this->grupo();
        $otro = $this->crearBloque($this->empresa, 'Otro');
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/grupos/{$grupo->id_grupo}/editar")
            ->assertOk()
            ->assertSee('Tesorería')
            ->assertDontSee('name="id_bloque"', false);

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Renombrado', 'id_bloque' => $otro->id_bloque])
            ->assertSessionHasNoErrors();

        $grupo->refresh();
        $this->assertSame('Renombrado', $grupo->nombre);
        $this->assertSame($this->bloque->id_bloque, $grupo->id_bloque);
    }

    public function test_al_editar_valida_el_nombre_dentro_de_su_bloque(): void
    {
        $this->grupo($this->bloque, 'Ocupado');
        $grupo = $this->grupo($this->bloque, 'Actual');
        $admin = $this->usuario();

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Ocupado'])
            ->assertSessionHasErrors(['nombre' => 'Este bloque ya tiene un grupo con este nombre.']);

        $this->actingAs($admin)->put("/grupos/{$grupo->id_grupo}", ['nombre' => 'Actual'])->assertSessionHasNoErrors();
    }

    // ===== Generación al crear un grupo =====

    public function test_crear_un_grupo_genera_solo_las_actividades_activas_de_su_bloque(): void
    {
        $general = $this->deBloque('General');
        $delBloque = $this->deBloque();

        // Ninguna de estas le corresponde.
        $this->deBloque('De otro bloque', $this->crearBloque($this->empresa, 'Otro'));
        $this->deBloque('Bloque ajeno', $this->crearBloque($this->crearEmpresa('OTRA'), 'Ajeno'));
        $this->deBloque('De bloque inactiva', null, false);

        $this->actingAs($this->usuario())
            ->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])
            ->assertRedirect(route('grupos.index'))
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 2 seguimientos.');

        $grupo = Grupo::where('nombre', 'Nuevo')->sole();
        $this->assertTrue($grupo->estado);
        $this->assertSame([$general->id_actividad, $delBloque->id_actividad], $this->actividadesDe($grupo));
    }

    public function test_los_seguimientos_generados_nacen_vacios(): void
    {
        $this->deBloque('General');

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo']);

        $seguimiento = Seguimiento::sole();
        $this->assertTrue($seguimiento->estado);
        $this->assertNull($seguimiento->estado_actividad);
        $this->assertNull($seguimiento->fecha_inicio);
        $this->assertNull($seguimiento->responsable);
    }

    public function test_crear_un_grupo_sin_actividades_lo_indica(): void
    {
        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Solo'])
            ->assertSessionHas('exito', 'Se creó el grupo Solo sin seguimientos todavía.');

        $this->assertSame(0, Seguimiento::count());
    }

    public function test_si_falla_la_generacion_tampoco_queda_el_grupo(): void
    {
        $this->deBloque('General');

        $this->app->instance(GeneradorDeSeguimientos::class, new class extends GeneradorDeSeguimientos
        {
            public function generarParaGrupo(Grupo $grupo): int
            {
                throw new RuntimeException('Fallo simulado');
            }
        });

        $this->actingAs($this->usuario())
            ->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Fallido'])
            ->assertServerError();

        $this->assertFalse(Grupo::where('nombre', 'Fallido')->exists());
        $this->assertSame(0, Seguimiento::count());
    }

    // ===== Generación al crear una actividad =====

    public function test_crear_una_actividad_avisa_antes_y_no_guarda_sin_confirmar(): void
    {
        $this->grupo($this->bloque, 'Grupo A');
        $this->grupo($this->bloque, 'Grupo B');
        $datos = [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'Nueva actividad de prueba',
            'proceso' => 'Pagos',
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

        $this->assertSame('Pagos', Actividad::sole()->proceso);
        $this->assertSame(2, Seguimiento::count());
    }

    public function test_sin_grupos_a_los_que_aplique_no_pide_confirmacion(): void
    {
        // Un grupo de otro bloque y uno de baja no reciben la actividad.
        $this->grupo($this->crearBloque($this->empresa, 'Otro'));
        $this->grupo($this->bloque, 'De baja', false);

        $this->actingAs($this->usuario())->post('/actividades', [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_bloque' => $this->bloque->id_bloque,
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
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'A corregir',
        ])->assertRedirect(route('actividades.crear'))->assertSessionHasInput('nombre', 'A corregir');

        $this->assertSame(0, Actividad::count());
    }

    public function test_confirmar_vuelve_a_validar(): void
    {
        $this->grupo();
        $this->deBloque('Ya existe');

        $this->actingAs($this->usuario())->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'Ya existe',
        ])->assertSessionHasErrors('nombre');

        $this->assertSame(1, Actividad::count());
    }

    public function test_ya_no_existe_la_opcion_todos_los_bloques(): void
    {
        $this->grupo($this->bloque, 'En un bloque');

        foreach (['todos', ''] as $valor) {
            $this->actingAs($this->usuario())->post('/actividades', [
                'accion' => 'confirmar',
                'id_empresa' => $this->empresa->id_empresa,
                'tipo' => 'Onboarding',
                'id_bloque' => $valor,
                'nombre' => 'Inducción',
            ])->assertSessionHasErrors('id_bloque');
        }

        $this->assertSame(0, Actividad::count());
        $this->assertSame(0, Seguimiento::count());
    }

    public function test_actividad_con_bloque_solo_llega_a_los_grupos_de_ese_bloque(): void
    {
        $propio = $this->grupo($this->bloque, 'Propio');
        $otro = $this->grupo($this->crearBloque($this->empresa, 'Otro'), 'De otro bloque');

        $this->actingAs($this->usuario())->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'Solo este bloque',
        ])->assertSessionHas('exito');

        $this->assertSame(1, $propio->seguimientos()->count());
        $this->assertSame(0, $otro->seguimientos()->count());
    }

    // ===== Generación por fase según el tipo =====

    public function test_onboarding_genera_un_seguimiento_en_la_fase_de_orden_1(): void
    {
        $this->deBloque('Solo una fase');

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 1 seguimiento.');

        $this->assertSame($this->fase(1)->id_fase, Seguimiento::sole()->id_fase);
    }

    public function test_transicion_genera_tres_seguimientos_uno_por_fase(): void
    {
        $actividad = $this->deBloque('Tres fases', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 3 seguimientos.');

        $this->assertSame(
            [$this->fase(2)->id_fase, $this->fase(3)->id_fase, $this->fase(4)->id_fase],
            Seguimiento::where('id_actividad', $actividad->id_actividad)->orderBy('id_fase')->pluck('id_fase')->all()
        );
    }

    public function test_un_bloque_con_ambos_tipos_suma_sus_fases(): void
    {
        $this->deBloque('Onboarding uno');
        $this->deBloque('Onboarding dos');
        $this->deBloque('Transición uno', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 5 seguimientos.');
    }

    public function test_el_onboarding_de_un_bloque_no_llega_a_otro_bloque(): void
    {
        $this->deBloque('Solo Tesorería');
        $otro = $this->crearBloque($this->empresa, 'Desempeño');
        $this->deBloque('Solo Desempeño', $otro);
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $otro->id_bloque, 'nombre' => 'De Desempeño']);

        $grupo = Grupo::where('nombre', 'De Desempeño')->sole();
        $this->assertSame(['Solo Desempeño'], $grupo->seguimientos()->with('actividad')->get()->pluck('actividad.nombre')->all());
    }

    public function test_crear_una_transicion_avisa_el_total_real_de_seguimientos(): void
    {
        $this->grupo($this->bloque, 'Grupo A');
        $this->grupo($this->bloque, 'Grupo B');
        $datos = [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Transición',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'Tres fases',
        ];
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/actividades', $datos)
            ->assertOk()
            ->assertSee('Se generarán 6 seguimientos')
            ->assertSee('uno por cada fase');

        $this->actingAs($admin)->post('/actividades', [...$datos, 'accion' => 'confirmar'])
            ->assertSessionHas('exito', 'Se creó la actividad Tres fases. Se generaron 6 seguimientos.');

        $this->assertSame(6, Seguimiento::count());
    }

    public function test_confirmar_conserva_el_bloque_elegido(): void
    {
        $this->grupo();

        $this->actingAs($this->usuario())->post('/actividades', [
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Onboarding',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'General',
        ])->assertOk()
            ->assertSee('del bloque Tesorería')
            ->assertSee('name="id_bloque" value="'.$this->bloque->id_bloque.'"', false);
    }

    public function test_reactivar_una_transicion_incompleta_solo_genera_las_fases_que_faltan(): void
    {
        $actividad = $this->deBloque('Tres fases', null, false, TipoActividad::Transicion);
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
        $this->deBloque('Tres fases', null, true, TipoActividad::Transicion);

        $this->actingAs($this->usuario())->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])
            ->assertSessionHas('exito', 'Se creó el grupo Nuevo con 3 seguimientos.');
    }

    // ===== Baja: los seguimientos no la bloquean y quedan intactos =====

    public function test_la_baja_avisa_cuantos_seguimientos_tiene_el_grupo_y_los_conserva(): void
    {
        $grupo = $this->grupo();
        $actividad = $this->deBloque('General');
        $otra = $this->deBloque();
        $this->seguimiento($grupo, $actividad, ['comentarios' => 'Se conserva']);
        $this->seguimiento($grupo, $otra);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos')
            ->assertSee('Tiene 2 seguimientos: quedan intactos');

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
        $actividad = $this->deBloque('General');
        $this->seguimiento($this->grupo($this->bloque, 'A'), $actividad, ['comentarios' => 'Se conserva']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades')
            ->assertSee('Tiene 1 seguimiento: queda intacto');

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
        $this->grupo($this->bloque, 'Activo');
        $this->grupo($this->bloque, 'Inactivo', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos')->assertSee('Activo')->assertDontSee('celda-titulo">Inactivo', false);
        $this->actingAs($admin)->get('/grupos?ver_bajas=1')->assertSee('celda-titulo">Inactivo', false);
    }

    public function test_los_seguimientos_de_baja_no_cuentan_en_el_trabajo(): void
    {
        $grupo = $this->grupo($this->bloque, 'A');
        $otroGrupo = $this->grupo($this->bloque, 'B');
        $actividad = $this->deBloque('Uno');
        $otraActividad = $this->deBloque('Dos');
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
        $existente = $this->deBloque('Existente');
        $grupo = $this->grupo($this->bloque, 'Grupo uno', false);
        $this->seguimiento($grupo, $existente, ['comentarios' => 'Se conserva']);
        $nueva = $this->deBloque('Creada durante la baja');
        $this->deBloque('Inactiva', null, false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos?ver_bajas=1')
            ->assertSee('Se generará 1 seguimiento para las actividades activas que le faltan', false);

        $this->assertSame(1, Seguimiento::count());

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó el grupo Grupo uno. Se generó 1 seguimiento.');

        $this->assertTrue($grupo->fresh()->estado);
        $this->assertSame([$existente->id_actividad, $nueva->id_actividad], $this->actividadesDe($grupo));
        $this->assertSame('Se conserva', Seguimiento::where('id_actividad', $existente->id_actividad)->sole()->comentarios);
    }

    public function test_reactivar_un_grupo_completo_no_genera_nada(): void
    {
        $actividad = $this->deBloque('General');
        $grupo = $this->grupo($this->bloque, 'Grupo uno', false);
        $this->seguimiento($grupo, $actividad);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/grupos?ver_bajas=1')->assertSee('No hace falta generar seguimientos nuevos.');

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó el grupo Grupo uno.');

        $this->assertSame(1, Seguimiento::count());
    }

    public function test_reactivar_una_actividad_avisa_y_genera_solo_para_grupos_activos(): void
    {
        $actividad = $this->deBloque('Actividad', null, false);
        $conSeguimiento = $this->grupo($this->bloque, 'Ya la tiene');
        $this->seguimiento($conSeguimiento, $actividad, ['comentarios' => 'Se conserva']);
        $sinSeguimiento = $this->grupo($this->bloque, 'Creado durante la baja');
        $inactivo = $this->grupo($this->bloque, 'De baja', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/actividades?ver_bajas=1')
            ->assertSee('Se generará 1 seguimiento para los grupos activos que no la tienen', false);

        $this->actingAs($admin)->from('/actividades')->patch("/actividades/{$actividad->id_actividad}/reactivar")
            ->assertSessionHas('exito', 'Se reactivó la actividad Actividad. Se generó 1 seguimiento.');

        $this->assertTrue($actividad->fresh()->estado);
        $this->assertSame(1, $sinSeguimiento->seguimientos()->count());
        $this->assertSame(0, $inactivo->seguimientos()->count());
        $this->assertSame('Se conserva', $conSeguimiento->seguimientos()->sole()->comentarios);
    }

    public function test_reactivar_dos_veces_no_duplica(): void
    {
        $this->deBloque('General');
        $grupo = $this->grupo($this->bloque, 'Grupo uno', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar");
        $this->actingAs($admin)->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar");

        $this->assertSame(1, Seguimiento::count());
    }

    public function test_el_generador_ignora_grupos_y_actividades_de_baja(): void
    {
        $generador = new GeneradorDeSeguimientos;
        $actividadActiva = $this->deBloque('Activa');
        $actividadInactiva = $this->deBloque('Inactiva', null, false);
        $grupoActivo = $this->grupo($this->bloque, 'Activo');
        $grupoInactivo = $this->grupo($this->bloque, 'Inactivo', false);

        $this->assertSame(0, $generador->generarParaGrupo($grupoInactivo));
        $this->assertSame(0, $generador->generarParaActividad($actividadInactiva));
        $this->assertSame(0, Seguimiento::count());

        $this->assertSame(1, $generador->generarParaGrupo($grupoActivo->fresh()));
        $this->assertSame([$actividadActiva->id_actividad], $this->actividadesDe($grupoActivo));
    }

    public function test_no_se_reactiva_si_su_bloque_esta_de_baja(): void
    {
        $this->deBloque('General');
        $inactivo = $this->crearBloque($this->empresa, 'Inactivo', false);
        $grupo = $this->grupo($inactivo, 'Grupo uno', false);

        $this->actingAs($this->usuario())->from('/grupos')->patch("/grupos/{$grupo->id_grupo}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar Grupo uno: su bloque está dado de baja.');

        $this->assertFalse($grupo->fresh()->estado);
        $this->assertSame(0, Seguimiento::count());
    }

    public function test_la_baja_de_un_bloque_se_bloquea_con_grupos_activos(): void
    {
        $grupo = $this->grupo();
        $admin = $this->usuario();
        $url = "/bloques/{$this->bloque->id_bloque}/baja";

        $this->actingAs($admin)->from('/bloques')->patch($url)
            ->assertSessionHas('error', 'No se puede dar de baja Tesorería. Todavía tiene activos: grupos.');
        $this->assertTrue($this->bloque->fresh()->estado);

        $grupo->update(['estado' => false]);

        $this->actingAs($admin)->from('/bloques')->patch($url)->assertSessionHas('exito');
        $this->assertFalse($this->bloque->fresh()->estado);
    }

    public function test_el_menu_y_el_inicio_muestran_grupos(): void
    {
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/')
            ->assertOk()
            ->assertSee(route('grupos.index'), false);
    }
}
