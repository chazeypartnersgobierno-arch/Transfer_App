<?php

namespace Tests\Feature;

use App\Enums\EstadoActividad;
use App\Enums\EstadoAcceso;
use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\AccesoSolicitado;
use App\Models\Actividad;
use App\Models\Aplicativo;
use App\Models\Asistencia;
use App\Models\AvanceValidacion;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Seguimiento;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Prueba de punta a punta: recorre por las pantallas (peticiones HTTP) el seguimiento del proceso
 * "Selección y contratación" de EPM, como lo haría el consultor, desde Onboarding hasta Validación.
 *
 * A diferencia de las pruebas de cada módulo, aquí los datos se crean igual que en la app
 * (el admin crea grupos y actividades y los seguimientos se generan solos) y cada paso usa lo
 * que dejó el anterior. "Hoy" es el 15/04/2027 para que las fechas no dependan del día en que se corra.
 *
 * Es solo una prueba: no cambia la app ni toca la base de trabajo. Corre sobre transfer_app_testing
 * (RefreshDatabase la vacía en cada prueba). Para correrla sola: php artisan test --group=punta-a-punta
 */
#[Group('punta-a-punta')]
class FlujoSeleccionYContratacionTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $epm;

    private Proceso $proceso;

    private Usuario $admin;

    private Usuario $consultor;

    private Grupo $grupo1;

    private Grupo $grupo2;

    /** @var array<string, Actividad> Actividades por clave corta. */
    private array $actividades = [];

    /** @var array<string, Participante> */
    private array $participantes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-04-15 10:00:00');
        (new FaseSeeder)->run();

        $this->epm = Empresa::create(['nombre' => 'Empresas Públicas de Medellín', 'sigla' => 'EPM']);
        $this->proceso = Proceso::create(['id_empresa' => $this->epm->id_empresa, 'nombre' => 'Selección y contratación']);
        $this->admin = $this->usuario('admin@chazey.test', RolApp::Admin);
        $this->consultor = $this->asignarEmpresa($this->usuario('consultor@chazey.test', RolApp::Consultor), $this->epm);

        $this->armarCatalogoComoAdmin();
        $this->registrarParticipantesComoConsultor();
    }

    // ------------------------------------------------------------------
    // Pruebas
    // ------------------------------------------------------------------

    public function test_la_generacion_crea_los_seguimientos_por_tipo_y_grupo(): void
    {
        // 3 de Onboarding (1 fase) + 2 de Transición (3 fases) = 9 por grupo, 18 en total.
        $this->assertSame(18, Seguimiento::count());

        foreach ([$this->grupo1, $this->grupo2] as $grupo) {
            $this->assertSame(3, $this->deLaFase($grupo, 1)->count());
            $this->assertSame(2, $this->deLaFase($grupo, 2)->count());
            $this->assertSame(2, $this->deLaFase($grupo, 3)->count());
            $this->assertSame(2, $this->deLaFase($grupo, 4)->count());
        }

        // Los generados por la app quedan sin autor y en Pendiente (sin valor).
        $this->assertSame(0, Seguimiento::whereNotNull('id_usuario')->count());
        $this->assertSame(EstadoActividad::Pendiente, $this->seguimiento($this->grupo1, 'induccion', 1)->estadoActual());
    }

    public function test_el_equipo_del_proceso_se_copia_a_todos_sus_seguimientos(): void
    {
        $lista = route('seguimientos.index', ['id_proceso' => $this->proceso->id_proceso]);
        $this->actingAs($this->consultor)->from($lista)
            ->put(route('seguimientos.equipo.actualizar', $this->proceso), $this->equipo())
            ->assertRedirect($lista)
            ->assertSessionHas('exito', fn ($m) => str_contains($m, '18 seguimientos'));

        $this->assertSame(18, Seguimiento::where('responsable', 'Laura Gómez')->where('lider_transferencia_gbs', 'Andrés Ruiz')->count());
        // No cambia el autor.
        $this->assertSame(0, Seguimiento::whereNotNull('id_usuario')->count());

        // Un seguimiento editado después conserva el equipo y solo cambia su experto.
        $seguimiento = $this->seguimiento($this->grupo1, 'induccion', 1);
        $this->guardarSeguimiento($seguimiento, ['experto' => 'Marta Ríos'])->assertSessionHasNoErrors();
        $seguimiento->refresh();
        $this->assertSame('Laura Gómez', $seguimiento->responsable);
        $this->assertSame('Marta Ríos', $seguimiento->experto);
        $this->assertSame($this->consultor->id_usuario, $seguimiento->id_usuario);
    }

    public function test_flujo_completo_de_onboarding_a_validacion(): void
    {
        $this->actingAs($this->consultor);

        // ---------- 1. Onboarding: sin fechas no hay sesiones ----------
        $induccionG1 = $this->seguimiento($this->grupo1, 'induccion', 1);
        $induccionG2 = $this->seguimiento($this->grupo2, 'induccion', 1);

        $this->get(route('sesiones.crear', $induccionG1))
            ->assertRedirect(route('seguimientos.editar', $induccionG1))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Primero captura la fecha de inicio'));

        // Transferencia todavía está bloqueada para el grupo 1 (Onboarding 0 de 3).
        $reclutamientoG1 = $this->seguimiento($this->grupo1, 'reclutamiento', 2);
        $this->get(route('sesiones.crear', $reclutamientoG1))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'concluya Onboarding') && str_contains($m, '0 de 3'));
        $this->guardarSeguimiento($reclutamientoG1, ['fecha_inicio' => '2027-03-15', 'fecha_fin' => '2027-03-31'])
            ->assertSessionHas('error');
        $this->assertNull($reclutamientoG1->refresh()->fecha_inicio, 'Una fase bloqueada no debe guardar cambios.');

        // ---------- 2. Fechas y estado En curso en todo el Onboarding ----------
        foreach ([$this->grupo1, $this->grupo2] as $grupo) {
            foreach ($this->deLaFase($grupo, 1) as $seguimiento) {
                $this->guardarSeguimiento($seguimiento, [
                    'fecha_inicio' => '2027-03-01',
                    'fecha_fin' => '2027-03-12',
                    'estado_actividad' => EstadoActividad::EnCurso->value,
                ])->assertSessionHasNoErrors()->assertSessionHas('exito');
            }
        }

        // fecha_fin anterior a fecha_inicio: se rechaza.
        $this->guardarSeguimiento($induccionG1, ['fecha_inicio' => '2027-03-10', 'fecha_fin' => '2027-03-01'])
            ->assertSessionHasErrors('fecha_fin');

        // ---------- 3. Una sesión para los dos grupos a la vez ----------
        $this->get(route('sesiones.crear', $induccionG1))->assertOk()->assertSee('Inducción general de CCS');

        $this->post(route('sesiones.guardar', $induccionG1), [
            'seguimientos' => [$induccionG1->id_seguimiento, $induccionG2->id_seguimiento],
            'fecha' => '2027-03-03',
            'tema' => 'Inducción general',
            'modalidad' => 'Virtual',
            'asistencia' => [
                $induccionG1->id_seguimiento => [
                    $this->participantes['ana']->id_participante => ['asistio' => '1'],
                    // Bruno no asistió (casilla sin marcar) pero lleva comentario.
                    $this->participantes['bruno']->id_participante => ['comentarios' => 'Incapacidad'],
                    // Carla ingresa el 05/03: aunque llegue en el formulario, no se guarda.
                    $this->participantes['carla']->id_participante => ['asistio' => '1'],
                ],
                $induccionG2->id_seguimiento => [
                    $this->participantes['diego']->id_participante => ['asistio' => '1'],
                ],
            ],
        ])->assertRedirect(route('seguimientos.editar', $induccionG1))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'en 2 grupos'));

        $sesionG1 = Sesion::where('id_seguimiento', $induccionG1->id_seguimiento)->sole();
        $sesionG2 = Sesion::where('id_seguimiento', $induccionG2->id_seguimiento)->sole();
        $this->assertSame($this->consultor->id_usuario, $sesionG1->id_usuario);

        // Grupo 1: Ana y Bruno (Carla todavía no había ingresado). Grupo 2: Diego y Elena.
        $this->assertEqualsCanonicalizing(
            [$this->participantes['ana']->id_participante, $this->participantes['bruno']->id_participante],
            $sesionG1->asistencias()->pluck('id_participante')->all(),
        );
        $this->assertTrue($this->asistio($sesionG1, 'ana'));
        $this->assertFalse($this->asistio($sesionG1, 'bruno'));
        $this->assertSame('Incapacidad', Asistencia::where('id_sesion', $sesionG1->id_sesion)
            ->where('id_participante', $this->participantes['bruno']->id_participante)->value('comentarios'));
        $this->assertSame(2, $sesionG2->asistencias()->count());
        $this->assertFalse($this->asistio($sesionG2, 'elena'), 'La casilla empieza sin marcar.');

        // ---------- 4. Validaciones de la sesión ----------
        // Fuera de las fechas del seguimiento.
        $this->post(route('sesiones.guardar', $induccionG1), $this->sesion([$induccionG1], '2027-03-20'))
            ->assertSessionHasErrors('fecha');
        // Misma fecha: primero avisa, con confirmación sí guarda.
        $this->post(route('sesiones.guardar', $induccionG1), $this->sesion([$induccionG1], '2027-03-03'))
            ->assertRedirect(route('sesiones.crear', $induccionG1))
            ->assertSessionHas('repetidas', ['1']);
        $this->post(route('sesiones.guardar', $induccionG1), $this->sesion([$induccionG1], '2027-03-03') + ['confirmar_repetida' => '1'])
            ->assertSessionHas('exito');
        $this->assertSame(2, $induccionG1->sesiones()->count());
        // Modalidad fuera de la lista.
        $this->post(route('sesiones.guardar', $induccionG1), ['modalidad' => 'Telepatía'] + $this->sesion([$induccionG1], '2027-03-04'))
            ->assertSessionHasErrors('modalidad');
        // Onboarding registra sesiones, no avances.
        $this->get(route('avances.crear', $induccionG1))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'registra sesiones'));
        // Con sesiones, no se pueden quitar las fechas ni dejar sesiones fuera.
        $this->guardarSeguimiento($induccionG1, ['fecha_inicio' => null, 'fecha_fin' => null])
            ->assertSessionHasErrors('fecha_inicio');
        $this->guardarSeguimiento($induccionG1, ['fecha_inicio' => '2027-03-05', 'fecha_fin' => '2027-03-12'])
            ->assertSessionHasErrors('fecha_inicio');

        // ---------- 5. Editar la sesión: entra Carla si cambia la fecha ----------
        $this->get(route('sesiones.editar', $sesionG1))->assertOk()->assertSee('Inducción general');
        $this->put(route('sesiones.actualizar', $sesionG1), [
            'fecha' => '2027-03-06',
            'tema' => 'Inducción general (corregida)',
            'modalidad' => 'Híbrida',
            'asistencia' => [$induccionG1->id_seguimiento => [
                $this->participantes['ana']->id_participante => ['asistio' => '1'],
                $this->participantes['bruno']->id_participante => ['asistio' => '1'],
                $this->participantes['carla']->id_participante => ['asistio' => '1'],
            ]],
        ])->assertSessionHasNoErrors()->assertSessionHas('exito');
        $this->assertSame(3, $sesionG1->asistencias()->count());
        $this->assertTrue($this->asistio($sesionG1, 'bruno'));

        // ---------- 6. Retrasado: hoy (15/04) es posterior a la fecha fin (12/03) ----------
        $this->get(route('seguimientos.index', ['estado' => 'Retrasado', 'id_grupo' => $this->grupo1->id_grupo]))
            ->assertOk()
            ->assertViewHas('seguimientos', fn ($pagina) => $pagina->total() === 3);

        // ---------- 7. Completar Onboarding del grupo 1 ----------
        $this->completarFase($this->grupo1, 1);
        $this->assertEquals(today(), $induccionG1->refresh()->fecha_cierre, 'fecha_cierre se llena sola al completar.');

        // El grupo 1 avanza, el grupo 2 sigue bloqueado (cada grupo va por su cuenta).
        $this->get(route('grupos.fases', $this->grupo1))->assertOk()
            ->assertViewHas('elegida', fn ($p) => $p->fase->orden === 2)
            ->assertViewHas('bloqueo', null);
        $this->get(route('grupos.fases', $this->grupo2))->assertOk()
            ->assertViewHas('elegida', fn ($p) => $p->fase->orden === 1);
        $this->get(route('grupos.fases', ['grupo' => $this->grupo2, 'fase' => $this->fase(2)->id_fase]))
            ->assertViewHas('bloqueo', fn ($m) => str_contains($m, '0 de 3'))
            ->assertViewHas('seguimientos', fn ($s) => $s->isEmpty());

        // ---------- 8. Transferencia del grupo 1 ----------
        foreach ($this->deLaFase($this->grupo1, 2) as $seguimiento) {
            $this->guardarSeguimiento($seguimiento, [
                'fecha_inicio' => '2027-03-15',
                'fecha_fin' => '2027-03-31',
                'estado_actividad' => EstadoActividad::EnCurso->value,
            ])->assertSessionHasNoErrors();
        }
        $reclutamientoG1->refresh();

        // Si se manda también el grupo 2 (fase bloqueada, sin fechas porque no se pueden capturar),
        // se rechaza toda la sesión.
        $reclutamientoG2 = $this->seguimiento($this->grupo2, 'reclutamiento', 2);
        $this->post(route('sesiones.guardar', $reclutamientoG1), $this->sesion([$reclutamientoG1, $reclutamientoG2], '2027-03-17'))
            ->assertSessionHasErrors('seguimientos.1');
        $this->assertSame(0, Sesion::where('id_seguimiento', $reclutamientoG1->id_seguimiento)->count());

        $this->post(route('sesiones.guardar', $reclutamientoG1), $this->sesion([$reclutamientoG1], '2027-03-17', ['ana', 'carla']))
            ->assertSessionHasNoErrors()->assertSessionHas('exito');
        $sesionTransferencia = $reclutamientoG1->sesiones()->sole();
        $this->assertSame(3, $sesionTransferencia->asistencias()->count());
        $this->assertTrue($this->asistio($sesionTransferencia, 'carla'));

        $this->completarFase($this->grupo1, 2);

        // ---------- 9. Validación del grupo 1: avances, no sesiones ----------
        $validacionG1 = $this->seguimiento($this->grupo1, 'reclutamiento', 3);
        $this->get(route('sesiones.crear', $validacionG1))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no registra sesiones'));
        $this->get(route('avances.crear', $validacionG1))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Primero captura la fecha'));

        $this->guardarSeguimiento($validacionG1, [
            'fecha_inicio' => '2027-04-01',
            'fecha_fin' => '2027-04-30',
            'estado_actividad' => EstadoActividad::EnCurso->value,
        ])->assertSessionHasNoErrors();
        $validacionG1->refresh();

        $this->get(route('avances.crear', $validacionG1))->assertOk();

        // Reglas de la captura.
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-20', 25, 20))
            ->assertSessionHasErrors('fecha_corte'); // futura
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-03-20', 25, 20))
            ->assertSessionHasErrors('fecha_corte'); // fuera del rango
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-05', 30, 20))
            ->assertSessionHasErrors('porcentaje_esperado'); // no es 0/25/50/75/100
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-05', 25, 120))
            ->assertSessionHasErrors('porcentaje_real'); // más de 100
        $this->assertSame(0, $validacionG1->avances()->count());

        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-12', 50, 45))->assertSessionHas('exito');
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-05', 25, 20))->assertSessionHas('exito');
        // El % actual es el de la fecha de corte mayor, aunque se haya capturado antes.
        $this->assertSame(45, $validacionG1->refresh()->avanceActual->porcentaje_real);

        // Fecha de corte repetida: se avisa y se ofrece editar la existente.
        $existente = $validacionG1->avances()->whereDate('fecha_corte', '2027-04-12')->sole();
        $this->post(route('avances.guardar', $validacionG1), $this->avance('2027-04-12', 75, 70))
            ->assertSessionHasErrors('fecha_corte')
            ->assertSessionHas('avance_existente', $existente->id_avance_validacion);

        // Se corrige editando.
        $this->get(route('avances.editar', $existente))->assertOk();
        $this->put(route('avances.actualizar', $existente), $this->avance('2027-04-12', 50, 55, 'Ajuste tras revisión'))
            ->assertSessionHasNoErrors()->assertSessionHas('exito');
        $this->assertSame(55, $validacionG1->refresh()->avanceActual->porcentaje_real);
        $this->assertSame(2, AvanceValidacion::count());

        // Con avances, la fecha fin no puede dejar fuera los cortes.
        $this->guardarSeguimiento($validacionG1, ['fecha_inicio' => '2027-04-01', 'fecha_fin' => '2027-04-10'])
            ->assertSessionHasErrors('fecha_inicio');

        // En la lista se ve el % actual.
        $this->get(route('seguimientos.index', ['id_fase' => $this->fase(3)->id_fase, 'id_grupo' => $this->grupo1->id_grupo]))
            ->assertOk()
            ->assertSee('55');

        // ---------- 10. Completar Validación: se habilita Estabilización ----------
        $this->completarFase($this->grupo1, 3);
        $this->get(route('grupos.fases', $this->grupo1))
            ->assertViewHas('elegida', fn ($p) => $p->fase->orden === 4)
            ->assertViewHas('bloqueo', null)
            ->assertViewHas('seguimientos', fn ($s) => $s->count() === 2);

        // El grupo 2 sigue en Onboarding y no se vio afectado.
        $this->assertSame(0, $this->deLaFase($this->grupo2, 2)->whereNotNull('fecha_inicio')->count());
    }

    public function test_reabrir_onboarding_bloquea_las_fases_siguientes_sin_perder_lo_capturado(): void
    {
        $this->actingAs($this->consultor);

        // Grupo 1 con Onboarding y Transferencia completos y un avance en Validación.
        foreach ([1 => ['2027-03-01', '2027-03-12'], 2 => ['2027-03-15', '2027-03-31'], 3 => ['2027-04-01', '2027-04-30']] as $orden => [$inicio, $fin]) {
            foreach ($this->deLaFase($this->grupo1, $orden) as $seguimiento) {
                $this->guardarSeguimiento($seguimiento, ['fecha_inicio' => $inicio, 'fecha_fin' => $fin])->assertSessionHasNoErrors();
            }
            if ($orden < 3) {
                $this->completarFase($this->grupo1, $orden);
            }
        }
        $validacion = $this->seguimiento($this->grupo1, 'aseguramiento', 3);
        $this->post(route('avances.guardar', $validacion), $this->avance('2027-04-10', 25, 30))->assertSessionHas('exito');
        $avance = $validacion->avances()->sole();

        // Reabrir una actividad de Onboarding: avisa y vacía su fecha de cierre.
        $induccion = $this->seguimiento($this->grupo1, 'induccion', 1);
        $this->guardarSeguimiento($induccion, ['estado_actividad' => EstadoActividad::EnCurso->value])
            ->assertSessionHas('aviso', fn ($m) => str_contains($m, 'ya no está completa'));
        $this->assertNull($induccion->refresh()->fecha_cierre);

        // Transferencia y Validación vuelven a bloquearse: solo se consultan.
        $this->get(route('grupos.fases', $this->grupo1))->assertViewHas('elegida', fn ($p) => $p->fase->orden === 1);
        $this->get(route('seguimientos.editar', $validacion))->assertOk()->assertViewHas('bloqueo', fn ($m) => str_contains($m, '2 de 3'));
        $this->put(route('avances.actualizar', $avance), $this->avance('2027-04-10', 50, 60))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'concluya Onboarding'));
        $this->assertSame(30, $avance->refresh()->porcentaje_real, 'Lo capturado queda intacto.');

        // El equipo del proceso sí se edita aunque haya fases bloqueadas.
        $this->put(route('seguimientos.equipo.actualizar', $this->proceso), $this->equipo())->assertSessionHas('exito');

        // Al completar otra vez, fecha_cierre se vuelve a llenar y todo se desbloquea.
        $this->travelTo('2027-04-16 09:00:00');
        $this->guardarSeguimiento($induccion, ['estado_actividad' => EstadoActividad::Completada->value])->assertSessionMissing('aviso');
        $this->assertEquals(today(), $induccion->refresh()->fecha_cierre);
        $this->put(route('avances.actualizar', $avance), $this->avance('2027-04-10', 50, 60))->assertSessionHas('exito');
    }

    public function test_un_consultor_de_otra_empresa_no_ve_ni_toca_el_seguimiento_de_epm(): void
    {
        $essa = Empresa::create(['nombre' => 'Electrificadora de Santander', 'sigla' => 'ESSA']);
        $otro = $this->asignarEmpresa($this->usuario('otro@chazey.test', RolApp::Consultor), $essa);

        $seguimiento = $this->seguimiento($this->grupo1, 'induccion', 1);
        $this->actingAs($this->consultor);
        $this->guardarSeguimiento($seguimiento, ['fecha_inicio' => '2027-03-01', 'fecha_fin' => '2027-03-12'])->assertSessionHasNoErrors();
        $this->post(route('sesiones.guardar', $seguimiento), $this->sesion([$seguimiento->refresh()], '2027-03-03'))->assertSessionHas('exito');
        $sesion = $seguimiento->sesiones()->sole();

        $this->actingAs($otro);
        $this->get(route('seguimientos.index'))->assertOk()->assertViewHas('seguimientos', fn ($p) => $p->total() === 0);
        $this->get(route('seguimientos.editar', $seguimiento))->assertNotFound();
        $this->put(route('seguimientos.actualizar', $seguimiento), ['estado_actividad' => 'Completada'])->assertNotFound();
        $this->get(route('sesiones.editar', $sesion))->assertNotFound();
        $this->get(route('grupos.fases', $this->grupo1))->assertNotFound();
        $this->put(route('seguimientos.equipo.actualizar', $this->proceso), $this->equipo())->assertNotFound();
        $this->get(route('participantes.editar', $this->participantes['ana']))->assertNotFound();

        // El consultor no administra catálogos.
        $this->actingAs($this->consultor)
            ->post(route('grupos.guardar'), ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 3'])
            ->assertForbidden();

        $this->assertNotSame(EstadoActividad::Completada, $seguimiento->refresh()->estado_actividad);
    }

    public function test_participantes_y_accesos_del_proceso(): void
    {
        $this->actingAs($this->consultor);
        $hcm = Aplicativo::create(['id_empresa' => $this->epm->id_empresa, 'nombre' => 'HCM']);

        // Correo repetido (sin importar mayúsculas): se rechaza.
        $this->post(route('participantes.guardar'), $this->participante($this->grupo1, 'Otra', 'Persona', 'ANA@EPM.TEST'))
            ->assertSessionHasErrors('correo');

        // Cambiar de grupo dentro de la misma empresa.
        $elena = $this->participantes['elena'];
        $this->put(route('participantes.actualizar', $elena), $this->participante($this->grupo1, 'Elena', 'Vargas', 'elena@epm.test'))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->grupo1->id_grupo, $elena->refresh()->id_grupo);

        // Solicitud de acceso para varios a la vez.
        $this->post(route('accesos.guardar'), $this->solicitud($hcm, ['ana', 'bruno']))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, '2 solicitudes'));
        // Otra vez con uno nuevo: se omite a quien ya tiene una en curso.
        $this->post(route('accesos.guardar'), $this->solicitud($hcm, ['ana', 'diego']))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'Diego'))
            ->assertSessionHas('aviso', fn ($m) => str_contains($m, 'Ana'));
        $this->assertSame(3, AccesoSolicitado::count());
        $this->assertSame(3, AccesoSolicitado::where('estado', EstadoAcceso::Solicitado)->count());

        // Otorgado exige fecha_otorgado; al salir de Otorgado se vacía.
        $acceso = AccesoSolicitado::where('id_participante', $this->participantes['ana']->id_participante)->sole();
        $this->put(route('accesos.actualizar', $acceso), ['estado' => 'Otorgado', 'fecha_solicitud' => '2027-04-01'])
            ->assertSessionHasErrors('fecha_otorgado');
        $this->put(route('accesos.actualizar', $acceso), ['estado' => 'Otorgado', 'fecha_solicitud' => '2027-04-01', 'fecha_otorgado' => '2027-03-30'])
            ->assertSessionHasErrors('fecha_otorgado'); // anterior a la solicitud
        $this->put(route('accesos.actualizar', $acceso), ['estado' => 'Otorgado', 'fecha_solicitud' => '2027-04-01', 'fecha_otorgado' => '2027-04-08'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-04-08', $acceso->refresh()->fecha_otorgado->toDateString());
        $this->put(route('accesos.actualizar', $acceso), ['estado' => 'En trámite', 'fecha_solicitud' => '2027-04-01', 'fecha_otorgado' => '2027-04-08'])
            ->assertSessionHasNoErrors();
        $this->assertNull($acceso->refresh()->fecha_otorgado);

        // Baja de un participante: deja de aparecer en la asistencia de sesiones nuevas,
        // pero su asistencia pasada se conserva.
        $seguimiento = $this->seguimiento($this->grupo1, 'induccion', 1);
        $this->guardarSeguimiento($seguimiento, ['fecha_inicio' => '2027-03-01', 'fecha_fin' => '2027-03-31'])->assertSessionHasNoErrors();
        $seguimiento->refresh();
        $this->post(route('sesiones.guardar', $seguimiento), $this->sesion([$seguimiento], '2027-03-10', ['bruno']))->assertSessionHas('exito');
        $primera = $seguimiento->sesiones()->sole();

        $this->patch(route('participantes.baja', $this->participantes['bruno']))->assertSessionHas('exito');
        $this->post(route('sesiones.guardar', $seguimiento), $this->sesion([$seguimiento], '2027-03-20'))->assertSessionHas('exito');
        $segunda = $seguimiento->sesiones()->whereDate('fecha', '2027-03-20')->sole();

        $this->assertFalse($segunda->asistencias()->where('id_participante', $this->participantes['bruno']->id_participante)->exists());
        $this->assertTrue($this->asistio($primera, 'bruno'));
        // Al editar la primera sesión, Bruno sigue en su asistencia.
        $this->put(route('sesiones.actualizar', $primera), $this->sesion([$seguimiento], '2027-03-10', ['bruno']))->assertSessionHasNoErrors();
        $this->assertTrue($this->asistio($primera->refresh(), 'bruno'));
    }

    // ------------------------------------------------------------------
    // Preparación: lo que haría el admin y luego el consultor
    // ------------------------------------------------------------------

    private function armarCatalogoComoAdmin(): void
    {
        $this->actingAs($this->admin);

        // Grupo 1 sin actividades todavía: no genera seguimientos.
        $this->post(route('grupos.guardar'), ['id_proceso' => $this->proceso->id_proceso, 'nombre' => '1'])
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'sin seguimientos'));
        $this->grupo1 = Grupo::where('nombre', '1')->sole();

        $actividades = [
            'induccion' => [TipoActividad::Onboarding, 'Inducción general de CCS'],
            'politica' => [TipoActividad::Onboarding, 'Explicación de la politica, lineamiento y regla de negocio'],
            'hcm' => [TipoActividad::Onboarding, 'Uso de herramienta HCM'],
            'reclutamiento' => [TipoActividad::Transicion, 'Reclutamiento empleados públicos EPM'],
            'aseguramiento' => [TipoActividad::Transicion, 'Aseguramiento de empleados públicos EPM'],
        ];

        foreach ($actividades as $clave => [$tipo, $nombre]) {
            $datos = [
                'id_empresa' => $this->epm->id_empresa,
                'tipo' => $tipo->value,
                'id_proceso' => $this->proceso->id_proceso,
                'macroproceso' => 'Selección y contratación',
                'nombre' => $nombre,
            ];

            // Primero pide confirmación con cuántos seguimientos generará.
            $porGenerar = $tipo === TipoActividad::Onboarding ? 1 : 3;
            $this->post(route('actividades.guardar'), $datos)
                ->assertOk()
                ->assertViewIs('actividades.confirmar')
                ->assertViewHas('porGenerar', $porGenerar);

            $this->post(route('actividades.guardar'), $datos + ['accion' => 'confirmar'])
                ->assertRedirect(route('actividades.index'))
                ->assertSessionHas('exito');

            $this->actividades[$clave] = Actividad::where('nombre', $nombre)->sole();
        }

        // La misma actividad otra vez: se rechaza.
        $this->post(route('actividades.guardar'), [
            'id_empresa' => $this->epm->id_empresa, 'tipo' => 'Onboarding', 'id_proceso' => $this->proceso->id_proceso,
            'macroproceso' => 'Selección y contratación',
            'nombre' => 'Inducción general de CCS', 'accion' => 'confirmar',
        ])->assertSessionHasErrors();

        // Grupo 2, ya con actividades: genera 3 + 2×3 = 9.
        $this->post(route('grupos.guardar'), ['id_proceso' => $this->proceso->id_proceso, 'nombre' => '2'])
            ->assertSessionHas('exito', fn ($m) => str_contains($m, '9'));
        $this->grupo2 = Grupo::where('nombre', '2')->sole();
    }

    private function registrarParticipantesComoConsultor(): void
    {
        $this->actingAs($this->consultor);

        $lista = [
            'ana' => [$this->grupo1, 'Ana', 'Ramírez', '2027-02-15'],
            'bruno' => [$this->grupo1, 'Bruno', 'Salazar', null],
            // Carla entra al grupo después de la primera sesión de Onboarding.
            'carla' => [$this->grupo1, 'Carla', 'Toro', '2027-03-05'],
            'diego' => [$this->grupo2, 'Diego', 'Uribe', '2027-02-15'],
            'elena' => [$this->grupo2, 'Elena', 'Vargas', '2027-02-15'],
        ];

        foreach ($lista as $clave => [$grupo, $nombre, $apellidos, $ingreso]) {
            $this->post(route('participantes.guardar'), $this->participante($grupo, "  {$nombre} ", $apellidos, "{$clave}@EPM.test", $ingreso))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('exito');
            $this->participantes[$clave] = Participante::where('correo', "{$clave}@epm.test")->sole();
        }

        $this->assertSame('Ana', $this->participantes['ana']->nombre, 'El nombre se guarda sin espacios de más.');
    }

    // ------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------

    private function usuario(string $correo, RolApp $rol): Usuario
    {
        return Usuario::create([
            'nombre' => ucfirst(strtok($correo, '@')),
            'correo' => $correo,
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]);
    }

    private function fase(int $orden): Fase
    {
        return Fase::where('orden', $orden)->sole();
    }

    private function seguimiento(Grupo $grupo, string $actividad, int $orden): Seguimiento
    {
        return Seguimiento::where('id_grupo', $grupo->id_grupo)
            ->where('id_actividad', $this->actividades[$actividad]->id_actividad)
            ->where('id_fase', $this->fase($orden)->id_fase)
            ->sole();
    }

    private function deLaFase(Grupo $grupo, int $orden)
    {
        return Seguimiento::where('id_grupo', $grupo->id_grupo)->where('id_fase', $this->fase($orden)->id_fase)->orderBy('id_actividad')->get();
    }

    /**
     * Envía el formulario de edición del seguimiento con sus valores actuales más $cambios
     * (igual que la pantalla, que manda todos los campos).
     */
    private function guardarSeguimiento(Seguimiento $seguimiento, array $cambios)
    {
        $seguimiento->refresh();

        return $this->put(route('seguimientos.actualizar', $seguimiento), array_merge([
            'fecha_inicio' => $seguimiento->fecha_inicio?->toDateString(),
            'fecha_fin' => $seguimiento->fecha_fin?->toDateString(),
            'estado_actividad' => $seguimiento->estadoActual()->value,
            'documentacion' => $seguimiento->documentacion,
            'comentarios' => $seguimiento->comentarios,
            'experto' => $seguimiento->experto,
        ], $cambios));
    }

    private function completarFase(Grupo $grupo, int $orden): void
    {
        foreach ($this->deLaFase($grupo, $orden) as $seguimiento) {
            $this->guardarSeguimiento($seguimiento, ['estado_actividad' => EstadoActividad::Completada->value])
                ->assertSessionHasNoErrors()
                ->assertSessionMissing('error');
        }
    }

    /**
     * Datos de una sesión para los seguimientos dados; $asistieron son claves de participantes.
     *
     * @param  list<Seguimiento>  $seguimientos
     */
    private function sesion(array $seguimientos, string $fecha, array $asistieron = []): array
    {
        $asistencia = [];
        foreach ($seguimientos as $seguimiento) {
            foreach ($asistieron as $clave) {
                $asistencia[$seguimiento->id_seguimiento][$this->participantes[$clave]->id_participante] = ['asistio' => '1'];
            }
        }

        return [
            'seguimientos' => array_map(fn (Seguimiento $s) => $s->id_seguimiento, $seguimientos),
            'fecha' => $fecha,
            'tema' => 'Sesión de transferencia',
            'modalidad' => 'Presencial',
            'asistencia' => $asistencia,
        ];
    }

    private function asistio(Sesion $sesion, string $clave): bool
    {
        return (bool) Asistencia::where('id_sesion', $sesion->id_sesion)
            ->where('id_participante', $this->participantes[$clave]->id_participante)
            ->value('asistio');
    }

    private function avance(string $corte, int $esperado, int $real, ?string $comentarios = null): array
    {
        return ['fecha_corte' => $corte, 'porcentaje_esperado' => $esperado, 'porcentaje_real' => $real, 'comentarios' => $comentarios];
    }

    private function equipo(): array
    {
        return [
            'responsable' => 'Laura Gómez',
            'documentador' => 'Pedro Díaz',
            'lider_transferencia' => 'Sofía Mejía',
            'lider_transferencia_gbs' => 'Andrés Ruiz',
        ];
    }

    private function participante(Grupo $grupo, string $nombre, string $apellidos, string $correo, ?string $ingreso = '2027-02-15'): array
    {
        return [
            'id_grupo' => $grupo->id_grupo,
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'correo' => $correo,
            'rol' => 'Analista de selección',
            'fecha_ingreso' => $ingreso,
        ];
    }

    private function solicitud(Aplicativo $aplicativo, array $claves): array
    {
        return [
            'id_empresa' => $this->epm->id_empresa,
            'id_aplicativo' => $aplicativo->id_aplicativo,
            'fecha_solicitud' => '2027-04-01',
            'participantes' => array_map(fn ($c) => $this->participantes[$c]->id_participante, $claves),
        ];
    }
}
