<?php

namespace Tests\Feature;

use App\Enums\EstadoActividad;
use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\AvanceValidacion;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Models\Sesion;
use App\Models\Usuario;
use App\Services\AvanceDeFases;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvanceDeFasesTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Bloque $bloque;

    private Grupo $grupo1;

    private Grupo $grupo2;

    private Actividad $onboarding1;

    private Actividad $onboarding2;

    private Actividad $transicion;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-03-20 10:00:00');
        (new FaseSeeder)->run();
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->bloque = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo1 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 1']);
        $this->grupo2 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 2']);
        $this->onboarding1 = $this->actividad('Conocer el área', TipoActividad::Onboarding);
        $this->onboarding2 = $this->actividad('Accesos', TipoActividad::Onboarding);
        $this->transicion = $this->actividad('Conciliar cuentas', TipoActividad::Transicion);

        // Cada grupo: 2 seguimientos de Onboarding y 1 de cada fase de Transición.
        foreach ([$this->grupo1, $this->grupo2] as $grupo) {
            $this->seguimiento($grupo, $this->onboarding1, 1);
            $this->seguimiento($grupo, $this->onboarding2, 1);
            foreach ([2, 3, 4] as $orden) {
                $this->seguimiento($grupo, $this->transicion, $orden);
            }
        }

        $this->admin = $this->usuario();
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

    private function actividad(string $nombre, TipoActividad $tipo): Actividad
    {
        return Actividad::create([
            'id_empresa' => $this->empresa->id_empresa,
            'id_bloque' => $this->bloque->id_bloque,
            'tipo' => $tipo,
            'nombre' => $nombre,
        ]);
    }

    private function fase(int $orden): Fase
    {
        return Fase::where('orden', $orden)->sole();
    }

    private function seguimiento(Grupo $grupo, Actividad $actividad, int $orden): Seguimiento
    {
        return Seguimiento::create([
            'id_actividad' => $actividad->id_actividad,
            'id_grupo' => $grupo->id_grupo,
            'id_fase' => $this->fase($orden)->id_fase,
            'fecha_inicio' => '2027-03-01',
            'fecha_fin' => '2027-03-31',
        ]);
    }

    private function de(Grupo $grupo, int $orden, ?Actividad $actividad = null): Seguimiento
    {
        return Seguimiento::where('id_grupo', $grupo->id_grupo)
            ->where('id_fase', $this->fase($orden)->id_fase)
            ->when($actividad, fn ($q) => $q->where('id_actividad', $actividad->id_actividad))
            ->firstOrFail();
    }

    /** Pone en Completada todos los seguimientos de una fase del grupo. */
    private function completar(Grupo $grupo, int $orden): void
    {
        Seguimiento::where('id_grupo', $grupo->id_grupo)
            ->where('id_fase', $this->fase($orden)->id_fase)
            ->get()
            ->each(fn (Seguimiento $s) => $s->update(['estado_actividad' => EstadoActividad::Completada]));
    }

    /** Un objeto nuevo cada vez: el servicio guarda sus cálculos mientras vive. */
    private function servicio(): AvanceDeFases
    {
        return new AvanceDeFases;
    }

    public function test_el_orden_de_las_fases_viene_de_fases_orden(): void
    {
        $this->assertSame(
            ['Onboarding', 'Transferencia', 'Validación', 'Estabilización'],
            $this->servicio()->fases()->pluck('nombre')->all()
        );
    }

    public function test_cada_fase_se_habilita_cuando_la_anterior_esta_completa(): void
    {
        $s = $this->servicio();
        $this->assertTrue($s->faseHabilitada($this->fase(1), $this->grupo1)); // Onboarding, siempre
        $this->assertFalse($s->faseHabilitada($this->fase(2), $this->grupo1));
        $this->assertSame(['terminadas' => 0, 'total' => 2], $s->avance($this->fase(1), $this->grupo1));
        $this->assertTrue($s->faseActual($this->grupo1)->is($this->fase(1)));

        // Una de dos actividades terminada: todavía no.
        $this->de($this->grupo1, 1, $this->onboarding1)->update(['estado_actividad' => EstadoActividad::Completada]);
        $this->de($this->grupo1, 1, $this->onboarding2)->update(['estado_actividad' => EstadoActividad::EnCurso]);
        $s = $this->servicio();
        $this->assertSame(['terminadas' => 1, 'total' => 2], $s->avance($this->fase(1), $this->grupo1));
        $this->assertFalse($s->faseCompleta($this->fase(1), $this->grupo1));
        $this->assertFalse($s->faseHabilitada($this->fase(2), $this->grupo1));

        $this->completar($this->grupo1, 1);
        $s = $this->servicio();
        $this->assertTrue($s->faseHabilitada($this->fase(2), $this->grupo1));
        $this->assertFalse($s->faseHabilitada($this->fase(3), $this->grupo1));
        $this->assertTrue($s->faseActual($this->grupo1)->is($this->fase(2)));

        $this->completar($this->grupo1, 2);
        $this->completar($this->grupo1, 3);
        $this->completar($this->grupo1, 4);
        $this->assertNull($this->servicio()->faseActual($this->grupo1));
    }

    public function test_el_avance_de_un_grupo_no_depende_de_los_otros(): void
    {
        $this->completar($this->grupo1, 1);
        $s = $this->servicio();

        $this->assertTrue($s->faseHabilitada($this->fase(2), $this->grupo1));
        $this->assertFalse($s->faseHabilitada($this->fase(2), $this->grupo2));
    }

    public function test_fase_sin_actividades_cuenta_como_completa_y_las_de_baja_no_cuentan(): void
    {
        $sinOnboarding = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 3']);
        $this->seguimiento($sinOnboarding, $this->transicion, 2);
        $this->assertTrue($this->servicio()->faseHabilitada($this->fase(2), $sinOnboarding));

        // Con una actividad de Onboarding pendiente pero de baja, el grupo 1 solo depende de la otra.
        $this->onboarding2->update(['estado' => false]);
        $this->de($this->grupo1, 1, $this->onboarding1)->update(['estado_actividad' => EstadoActividad::Completada]);
        $s = $this->servicio();
        $this->assertSame(['terminadas' => 1, 'total' => 1], $s->avance($this->fase(1), $this->grupo1));
        $this->assertTrue($s->faseHabilitada($this->fase(2), $this->grupo1));
    }

    public function test_el_mensaje_dice_que_fase_falta_y_su_avance(): void
    {
        $this->de($this->grupo1, 1, $this->onboarding1)->update(['estado_actividad' => EstadoActividad::Completada]);

        $this->assertSame(
            'Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 1 (1 de 2 actividades terminadas).',
            $this->servicio()->mensajeDeBloqueo($this->fase(2), $this->grupo1)
        );
        $this->assertNull($this->servicio()->mensajeDeBloqueo($this->fase(1), $this->grupo1));
    }

    public function test_reabrir_una_fase_bloquea_las_siguientes_y_avisa(): void
    {
        $this->completar($this->grupo1, 1);
        $this->completar($this->grupo1, 2);
        $this->assertTrue($this->servicio()->faseHabilitada($this->fase(3), $this->grupo1));

        $this->actingAs($this->admin)->put("/seguimientos/{$this->de($this->grupo1, 1, $this->onboarding1)->id_seguimiento}", ['estado_actividad' => 'En curso', 'fecha_inicio' => '2027-03-01', 'fecha_fin' => '2027-03-31'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('aviso', 'Onboarding del grupo Grupo 1 ya no está completa: las fases siguientes quedan bloqueadas hasta que se vuelva a completar. Lo ya capturado en ellas no se pierde.');

        // Todas las siguientes quedan bloqueadas, y el mensaje apunta a la fase que se reabrió.
        $s = $this->servicio();
        $this->assertFalse($s->faseHabilitada($this->fase(2), $this->grupo1));
        $this->assertFalse($s->faseHabilitada($this->fase(3), $this->grupo1));
        $this->assertStringContainsString('cuando concluya Onboarding', $s->mensajeDeBloqueo($this->fase(3), $this->grupo1));
    }

    public function test_el_seguimiento_de_una_fase_bloqueada_solo_se_consulta(): void
    {
        $bloqueado = $this->de($this->grupo1, 2);

        $this->actingAs($this->admin)->get("/seguimientos/{$bloqueado->id_seguimiento}/editar")
            ->assertOk()
            ->assertSee('Consultar seguimiento')
            ->assertSee('alert alert-warning aviso-bloqueo', false)
            ->assertSee('Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 1 (0 de 2 actividades terminadas).')
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('Nueva sesión');

        $this->actingAs($this->admin)->put("/seguimientos/{$bloqueado->id_seguimiento}", ['estado_actividad' => 'Completada'])
            ->assertSessionHas('error', 'Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 1 (0 de 2 actividades terminadas).');
        $this->assertNull($bloqueado->fresh()->estado_actividad);

        // Onboarding sí se edita.
        $this->actingAs($this->admin)->put("/seguimientos/{$this->de($this->grupo1, 1, $this->onboarding1)->id_seguimiento}", ['estado_actividad' => 'En curso'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');
    }

    public function test_la_lista_marca_las_fases_bloqueadas(): void
    {
        $this->completar($this->grupo1, 1);

        $respuesta = $this->actingAs($this->admin)->get('/seguimientos?id_grupo='.$this->grupo1->id_grupo)->assertOk();
        // Onboarding y Transferencia: Editar. Validación y Estabilización: bloqueadas.
        $this->assertSame(2, substr_count($respuesta->getContent(), 'Fase bloqueada: falta concluir la fase anterior'));
        $respuesta->assertSee('>Editar</a>', false)->assertSee('>Consultar</a>', false);
    }

    public function test_no_se_registran_sesiones_en_una_fase_bloqueada(): void
    {
        $bloqueado = $this->de($this->grupo1, 2);
        $datos = ['fecha' => '2027-03-10', 'tema' => 'Tema', 'modalidad' => 'Virtual', 'seguimientos' => [$bloqueado->id_seguimiento]];

        $this->actingAs($this->admin)->get("/seguimientos/{$bloqueado->id_seguimiento}/sesiones/crear")
            ->assertSessionHas('error', 'Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 1 (0 de 2 actividades terminadas).');
        $this->actingAs($this->admin)->post("/seguimientos/{$bloqueado->id_seguimiento}/sesiones", $datos)->assertSessionHas('error');

        // Con el grupo 1 habilitado y el grupo 2 no: el grupo 2 aparece sin poder elegirse y el servidor lo rechaza.
        $this->completar($this->grupo1, 1);
        $otro = $this->de($this->grupo2, 2);

        $this->actingAs($this->admin)->get("/seguimientos/{$bloqueado->id_seguimiento}/sesiones/crear")
            ->assertOk()
            ->assertSee('value="'.$otro->id_seguimiento.'" class="form-check-input" data-elegir-grupo  disabled', false) // dos espacios: el @checked vacío
            ->assertSee('Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 2');

        $this->actingAs($this->admin)->post("/seguimientos/{$bloqueado->id_seguimiento}/sesiones", [...$datos, 'seguimientos' => [$bloqueado->id_seguimiento, $otro->id_seguimiento]])
            ->assertSessionHasErrors(['seguimientos' => 'La fase todavía no está habilitada para: Grupo 2 (Esta fase se habilitará cuando concluya Onboarding del bloque Tesorería – Grupo 2 (0 de 2 actividades terminadas).)']);
        $this->assertSame(0, Sesion::count());

        $this->actingAs($this->admin)->post("/seguimientos/{$bloqueado->id_seguimiento}/sesiones", $datos)->assertSessionHas('exito');
        $sesion = Sesion::sole();

        // Si se reabre Onboarding, la sesión ya registrada solo se consulta.
        $this->de($this->grupo1, 1, $this->onboarding1)->update(['estado_actividad' => EstadoActividad::EnCurso]);
        $this->actingAs($this->admin)->get("/sesiones/{$sesion->id_sesion}/editar")
            ->assertOk()
            ->assertSee('Consultar sesión')
            ->assertSee('<fieldset disabled', false);
        $this->actingAs($this->admin)->put("/sesiones/{$sesion->id_sesion}", [...$datos, 'tema' => 'Cambio'])->assertSessionHas('error');
        $this->assertSame('Tema', $sesion->fresh()->tema);
    }

    public function test_no_se_registran_avances_en_una_fase_bloqueada(): void
    {
        $validacion = $this->de($this->grupo1, 3);
        $datos = ['fecha_corte' => '2027-03-10', 'porcentaje_esperado' => '50', 'porcentaje_real' => '40'];
        $this->completar($this->grupo1, 1);

        $this->actingAs($this->admin)->get("/seguimientos/{$validacion->id_seguimiento}/avances/crear")
            ->assertSessionHas('error', 'Esta fase se habilitará cuando concluya Transferencia del bloque Tesorería – Grupo 1 (0 de 1 actividad terminada).');
        $this->actingAs($this->admin)->post("/seguimientos/{$validacion->id_seguimiento}/avances", $datos)->assertSessionHas('error');
        $this->assertSame(0, AvanceValidacion::count());

        $this->completar($this->grupo1, 2);
        $this->actingAs($this->admin)->post("/seguimientos/{$validacion->id_seguimiento}/avances", $datos)->assertSessionHas('exito');
        $avance = AvanceValidacion::sole();

        $this->de($this->grupo1, 2)->update(['estado_actividad' => EstadoActividad::EnCurso]);
        $this->actingAs($this->admin)->get("/avances/{$avance->id_avance_validacion}/editar")->assertSee('Consultar avance');
        $this->actingAs($this->admin)->put("/avances/{$avance->id_avance_validacion}", [...$datos, 'porcentaje_real' => '90'])->assertSessionHas('error');
        $this->assertSame(40, $avance->fresh()->porcentaje_real);
    }

    public function test_pantalla_de_fases_del_grupo(): void
    {
        $this->completar($this->grupo1, 1);
        $url = "/grupos/{$this->grupo1->id_grupo}/fases";

        // Abre en la fase actual (Transferencia): Onboarding completa, Validación y Estabilización bloqueadas.
        $respuesta = $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertSeeInOrder(['Onboarding', 'Transferencia', 'Validación', 'Estabilización'])
            ->assertSee('Completa:')
            ->assertSee('fase-actual', false)
            ->assertSee('Conciliar cuentas')
            ->assertSee('0 de 1 actividad terminada.');
        $this->assertSame(2, substr_count($respuesta->getContent(), 'Bloqueada:'));

        // Una fase bloqueada no carga sus actividades: muestra el aviso.
        $this->actingAs($this->admin)->get($url.'?fase='.$this->fase(3)->id_fase)
            ->assertOk()
            ->assertSee('Esta fase se habilitará cuando concluya Transferencia del bloque Tesorería – Grupo 1 (0 de 1 actividad terminada).')
            ->assertDontSee('Conciliar cuentas');

        // Otra empresa no lo ve.
        $ajeno = $this->usuario(RolApp::Consultor, Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']));
        $this->actingAs($ajeno)->get($url)->assertNotFound();
        $this->actingAs($this->usuario(RolApp::Consultor, $this->empresa))->get($url)->assertOk();
    }

    public function test_la_migracion_pone_validacion_antes_de_estabilizacion(): void
    {
        // Simula una base con el orden anterior: Estabilización 3 y Validación 4.
        DB::table('fases')->where('tiene_sesiones', false)->update(['orden' => 4]);
        DB::table('fases')->where('nombre', 'Estabilización')->update(['orden' => 3]);

        $migracion = require database_path('migrations/2026_11_10_000001_poner_validacion_antes_de_estabilizacion.php');
        $migracion->up();
        $this->assertSame(['Onboarding', 'Transferencia', 'Validación', 'Estabilización'], Fase::orderBy('orden')->pluck('nombre')->all());

        // Correrla otra vez no cambia nada; down() regresa al orden anterior.
        $migracion->up();
        $this->assertSame(3, Fase::where('tiene_sesiones', false)->value('orden'));
        $migracion->down();
        $this->assertSame(['Onboarding', 'Transferencia', 'Estabilización', 'Validación'], Fase::orderBy('orden')->pluck('nombre')->all());
    }
}
