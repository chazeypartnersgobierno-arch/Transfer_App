<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\AvanceValidacion;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvanceValidacionTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Grupo $grupo;

    private Actividad $actividad;

    /** Seguimiento de la fase Validación (orden 3), del 1 al 31 de marzo de 2027. */
    private Seguimiento $seguimiento;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // "Hoy" es el 20 de marzo de 2027: los cortes del 21 en adelante son futuros.
        $this->travelTo('2027-03-20 10:00:00');

        (new FaseSeeder)->run();
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $bloque = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo = Grupo::create(['id_bloque' => $bloque->id_bloque, 'nombre' => 'Grupo 1']);
        $this->actividad = Actividad::create([
            'id_empresa' => $this->empresa->id_empresa,
            'id_bloque' => $bloque->id_bloque,
            'tipo' => TipoActividad::Transicion,
            'nombre' => 'Conciliar cuentas',
        ]);
        $this->seguimiento = $this->seguimiento();
        $this->admin = $this->usuario();
    }

    private function usuario(RolApp $rol = RolApp::Admin, ?Empresa $empresa = null, string $nombre = 'Prueba'): Usuario
    {
        $this->contador++;

        return $this->asignarEmpresa(Usuario::create([
            'nombre' => $nombre,
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]), $empresa);
    }

    private function seguimiento(int $orden = 3, array $extra = []): Seguimiento
    {
        return Seguimiento::create([
            'id_actividad' => $this->actividad->id_actividad,
            'id_grupo' => $this->grupo->id_grupo,
            'id_fase' => Fase::where('orden', $orden)->sole()->id_fase,
            'fecha_inicio' => '2027-03-01',
            'fecha_fin' => '2027-03-31',
            ...$extra,
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return [
            'fecha_corte' => '2027-03-10',
            'porcentaje_esperado' => '50',
            'porcentaje_real' => '40',
            'comentarios' => 'Va bien',
            ...$cambios,
        ];
    }

    private function crearAvance(array $cambios = [], ?Usuario $usuario = null, ?Seguimiento $seguimiento = null)
    {
        $seguimiento ??= $this->seguimiento;

        return $this->actingAs($usuario ?? $this->admin)->post("/seguimientos/{$seguimiento->id_seguimiento}/avances", $this->datos($cambios));
    }

    public function test_registra_el_avance_con_el_usuario_que_lo_captura(): void
    {
        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/avances/crear")
            ->assertOk()
            ->assertSee('Nuevo avance')
            ->assertSee('min="2027-03-01"', false)
            ->assertSee('max="2027-03-20"', false); // nunca después de hoy

        $this->crearAvance()
            ->assertRedirect(route('seguimientos.editar', $this->seguimiento))
            ->assertSessionHas('exito', 'Se registró el avance con corte al 10/03/2027.');

        $avance = AvanceValidacion::sole();
        $this->assertSame($this->admin->id_usuario, $avance->id_usuario);
        $this->assertSame('2027-03-10', $avance->fecha_corte->format('Y-m-d'));
        $this->assertSame(50, $avance->porcentaje_esperado);
        $this->assertSame(40, $avance->porcentaje_real);
        $this->assertSame('Va bien', $avance->comentarios);
    }

    public function test_el_consultor_registra_avances_y_otra_empresa_no(): void
    {
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);
        $ajeno = $this->usuario(RolApp::Consultor, Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']));

        $this->get("/seguimientos/{$this->seguimiento->id_seguimiento}/avances/crear")->assertRedirect(route('login'));
        $this->actingAs($ajeno)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/avances/crear")->assertNotFound();
        $this->crearAvance([], $ajeno)->assertNotFound();

        $this->crearAvance([], $consultor)->assertSessionHas('exito');

        $avance = AvanceValidacion::sole();
        $this->actingAs($ajeno)->get("/avances/{$avance->id_avance_validacion}/editar")->assertNotFound();
        $this->actingAs($ajeno)->put("/avances/{$avance->id_avance_validacion}", $this->datos())->assertNotFound();
    }

    public function test_las_fases_con_sesiones_no_registran_avances(): void
    {
        $transferencia = $this->seguimiento(2);

        $this->actingAs($this->admin)->get("/seguimientos/{$transferencia->id_seguimiento}/avances/crear")
            ->assertSessionHas('error', 'La fase Transferencia registra sesiones, no avances de validación.');
        $this->crearAvance([], null, $transferencia)->assertSessionHas('error');

        $this->assertSame(0, AvanceValidacion::count());
    }

    public function test_valida_los_porcentajes_y_los_datos_obligatorios(): void
    {
        $this->crearAvance(['fecha_corte' => '', 'porcentaje_esperado' => '', 'porcentaje_real' => ''])
            ->assertSessionHasErrors([
                'fecha_corte' => 'Escribe la fecha de corte.',
                'porcentaje_esperado' => 'Elige el % esperado.',
                'porcentaje_real' => 'Escribe el % real.',
            ]);

        $this->crearAvance(['porcentaje_esperado' => '30', 'porcentaje_real' => '101'])
            ->assertSessionHasErrors([
                'porcentaje_esperado' => 'Elige un % esperado de la lista.',
                'porcentaje_real' => 'El % real debe ser un número entero de 0 a 100.',
            ]);

        $this->crearAvance(['porcentaje_real' => '45.5'])
            ->assertSessionHasErrors(['porcentaje_real' => 'El % real debe ser un número entero de 0 a 100.']);

        $this->assertSame(0, AvanceValidacion::count());

        // Los extremos sí se aceptan.
        $this->crearAvance(['porcentaje_esperado' => '0', 'porcentaje_real' => '0'])->assertSessionHasNoErrors();
        $this->crearAvance(['fecha_corte' => '2027-03-11', 'porcentaje_esperado' => '100', 'porcentaje_real' => '100'])->assertSessionHasNoErrors();
        $this->assertSame(2, AvanceValidacion::count());
    }

    public function test_la_fecha_de_corte_debe_estar_dentro_de_las_fechas_y_no_ser_futura(): void
    {
        $this->crearAvance(['fecha_corte' => '2027-02-28'])
            ->assertSessionHasErrors(['fecha_corte' => 'La fecha de corte debe estar entre las fechas del seguimiento (del 01/03/2027 al 31/03/2027).']);

        $this->crearAvance(['fecha_corte' => '2027-03-21'])
            ->assertSessionHasErrors(['fecha_corte' => 'La fecha de corte no puede ser futura.']);

        $this->assertSame(0, AvanceValidacion::count());

        // Los extremos cuentan: el primer día del seguimiento y hoy.
        $this->crearAvance(['fecha_corte' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->crearAvance(['fecha_corte' => '2027-03-20'])->assertSessionHasNoErrors();
        $this->assertSame(2, AvanceValidacion::count());
    }

    public function test_sin_fecha_de_inicio_y_fecha_fin_no_se_registran_avances(): void
    {
        $this->seguimiento->update(['fecha_fin' => null]);

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/editar")
            ->assertDontSee('Nuevo avance')
            ->assertSee('Para registrar avances, primero captura y guarda la fecha de inicio y la fecha fin de este seguimiento.');

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/avances/crear")
            ->assertSessionHas('error', 'Primero captura la fecha de inicio y la fecha fin del seguimiento: los avances deben quedar dentro de esas fechas.');
        $this->crearAvance()->assertSessionHas('error');

        $this->assertSame(0, AvanceValidacion::count());
    }

    public function test_no_se_repite_la_fecha_de_corte_y_se_ofrece_editar_la_existente(): void
    {
        $this->crearAvance();
        $existente = AvanceValidacion::sole();

        $this->crearAvance(['porcentaje_real' => '60'])
            ->assertSessionHasErrors(['fecha_corte' => 'Ya hay un avance con corte al 10/03/2027 en este seguimiento. Corrígelo editando ese avance.'])
            ->assertSessionHas('avance_existente', $existente->id_avance_validacion);

        $this->assertSame(1, AvanceValidacion::count());
        $this->assertSame(40, $existente->fresh()->porcentaje_real);
    }

    public function test_editar_corrige_el_avance_y_guarda_a_quien_lo_corrigio(): void
    {
        $this->crearAvance();
        $avance = AvanceValidacion::sole();
        $corrige = $this->usuario(RolApp::Consultor, $this->empresa, 'Laura');

        $this->actingAs($corrige)->get("/avances/{$avance->id_avance_validacion}/editar")
            ->assertOk()
            ->assertSee('Editar avance')
            ->assertSee('Última captura o corrección: Prueba.');

        // La misma fecha del propio avance no cuenta como repetida.
        $this->actingAs($corrige)->put("/avances/{$avance->id_avance_validacion}", $this->datos(['porcentaje_real' => '55', 'comentarios' => '']))
            ->assertRedirect(route('seguimientos.editar', $this->seguimiento))
            ->assertSessionHas('exito', 'Se actualizó el avance con corte al 10/03/2027.');

        $avance->refresh();
        $this->assertSame(55, $avance->porcentaje_real);
        $this->assertNull($avance->comentarios);
        $this->assertSame($corrige->id_usuario, $avance->id_usuario);
    }

    public function test_al_editar_no_se_puede_usar_la_fecha_de_otro_avance(): void
    {
        $this->crearAvance(['fecha_corte' => '2027-03-05']);
        $this->crearAvance(['fecha_corte' => '2027-03-12']);
        $segundo = AvanceValidacion::whereDate('fecha_corte', '2027-03-12')->sole();

        $this->actingAs($this->admin)->put("/avances/{$segundo->id_avance_validacion}", $this->datos(['fecha_corte' => '2027-03-05']))
            ->assertSessionHasErrors(['fecha_corte' => 'Ya hay un avance con corte al 05/03/2027 en este seguimiento. Corrígelo editando ese avance.']);

        $this->actingAs($this->admin)->put("/avances/{$segundo->id_avance_validacion}", $this->datos(['fecha_corte' => '2027-03-25']))
            ->assertSessionHasErrors(['fecha_corte' => 'La fecha de corte no puede ser futura.']);

        $this->assertSame('2027-03-12', $segundo->fresh()->fecha_corte->format('Y-m-d'));
    }

    public function test_el_porcentaje_actual_es_la_captura_mas_reciente(): void
    {
        $this->crearAvance(['fecha_corte' => '2027-03-15', 'porcentaje_real' => '70']);
        $this->crearAvance(['fecha_corte' => '2027-03-05', 'porcentaje_real' => '30']); // capturado después, pero con corte anterior

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/editar")
            ->assertOk()
            ->assertSee('% actual: 70 %')
            ->assertSee('Nuevo avance');

        $this->actingAs($this->admin)->get('/seguimientos')
            ->assertOk()
            ->assertSee('data-etiqueta="% actual">70 %', false);
    }

    public function test_los_de_baja_solo_se_consultan(): void
    {
        $this->crearAvance();
        $avance = AvanceValidacion::sole();
        $this->grupo->update(['estado' => false]);

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento->id_seguimiento}/editar")
            ->assertDontSee('Nuevo avance')
            ->assertSee('Consultar');

        $this->actingAs($this->admin)->get("/avances/{$avance->id_avance_validacion}/editar")
            ->assertOk()
            ->assertSee('Consultar avance')
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('>Guardar<', false);

        $this->actingAs($this->admin)->put("/avances/{$avance->id_avance_validacion}", $this->datos(['porcentaje_real' => '90']))
            ->assertSessionHas('error', 'Este avance es de un grupo o una actividad dados de baja: solo se puede consultar.');
        $this->crearAvance(['fecha_corte' => '2027-03-11'])->assertSessionHas('error');

        $this->assertSame(40, $avance->fresh()->porcentaje_real);
        $this->assertSame(1, AvanceValidacion::count());
    }

    public function test_las_fechas_del_seguimiento_no_pueden_dejar_avances_fuera(): void
    {
        $this->crearAvance(['fecha_corte' => '2027-03-05']);
        $url = "/seguimientos/{$this->seguimiento->id_seguimiento}";
        $datos = ['estado_actividad' => 'En curso'];

        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '2027-03-10', 'fecha_fin' => '2027-03-31'])
            ->assertSessionHasErrors(['fecha_inicio' => 'Con estas fechas quedarían fuera avances ya registrados (cortes al 05/03/2027). Elige fechas que los incluyan o corrige primero esos avances.']);

        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '', 'fecha_fin' => ''])
            ->assertSessionHasErrors(['fecha_inicio' => 'No se pueden quitar las fechas: el seguimiento ya tiene avances.']);

        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '2027-03-05', 'fecha_fin' => '2027-04-30'])
            ->assertSessionHasNoErrors();
    }
}
