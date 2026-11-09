<?php

namespace Tests\Feature;

use App\Enums\Modalidad;
use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Asistencia;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Seguimiento;
use App\Models\Sesion;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SesionTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Bloque $bloque;

    private Grupo $grupo1;

    private Grupo $grupo2;

    private Actividad $actividad;

    /** Seguimientos de la actividad de Transición en la fase Transferencia (orden 2). */
    private Seguimiento $seguimiento1;

    private Seguimiento $seguimiento2;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->bloque = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo1 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 1']);
        $this->grupo2 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 2']);
        $this->actividad = Actividad::create([
            'id_empresa' => $this->empresa->id_empresa,
            'id_bloque' => $this->bloque->id_bloque,
            'tipo' => TipoActividad::Transicion,
            'nombre' => 'Conciliar cuentas',
        ]);
        $this->seguimiento1 = $this->seguimiento($this->grupo1);
        $this->seguimiento2 = $this->seguimiento($this->grupo2);
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

    /** Por defecto, con fechas del 1 al 31 de marzo de 2027: las sesiones deben caer dentro. */
    private function seguimiento(Grupo $grupo, int $orden = 2, ?Actividad $actividad = null, array $extra = []): Seguimiento
    {
        return Seguimiento::create([
            'id_actividad' => ($actividad ?? $this->actividad)->id_actividad,
            'id_grupo' => $grupo->id_grupo,
            'id_fase' => Fase::where('orden', $orden)->sole()->id_fase,
            'fecha_inicio' => '2027-03-01',
            'fecha_fin' => '2027-03-31',
            ...$extra,
        ]);
    }

    private function participante(Grupo $grupo, string $nombre, bool $estado = true): Participante
    {
        return Participante::create([
            'id_grupo' => $grupo->id_grupo,
            'nombre' => $nombre,
            'apellidos' => 'Prueba',
            'correo' => strtolower($nombre).'@empresa.com',
            'rol' => 'Analista',
            'estado' => $estado,
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return [
            'fecha' => '2027-03-10',
            'tema' => 'Conciliación bancaria',
            'modalidad' => 'Virtual',
            'seguimientos' => [$this->seguimiento1->id_seguimiento],
            ...$cambios,
        ];
    }

    private function crearSesion(array $cambios = [], ?Seguimiento $desde = null, ?Usuario $usuario = null)
    {
        $desde ??= $this->seguimiento1;

        return $this->actingAs($usuario ?? $this->admin)->post("/seguimientos/{$desde->id_seguimiento}/sesiones", $this->datos($cambios));
    }

    // ===== Permisos y fase =====

    public function test_el_consultor_registra_sesiones_y_otra_empresa_no(): void
    {
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);
        $ajeno = $this->usuario(RolApp::Consultor, Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']));

        $this->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")->assertRedirect(route('login'));

        $this->actingAs($ajeno)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")->assertNotFound();
        $this->crearSesion([], null, $ajeno)->assertNotFound();

        $this->actingAs($consultor)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")->assertOk();
        $this->crearSesion([], null, $consultor)->assertSessionHas('exito');

        $sesion = Sesion::sole();
        $this->assertSame($consultor->id_usuario, $sesion->id_usuario); // queda como autor
        $this->actingAs($ajeno)->get("/sesiones/{$sesion->id_sesion}/editar")->assertNotFound();
        $this->actingAs($ajeno)->put("/sesiones/{$sesion->id_sesion}", $this->datos())->assertNotFound();
    }

    public function test_validacion_no_registra_sesiones(): void
    {
        $validacion = $this->seguimiento($this->grupo1, 3);

        $this->actingAs($this->admin)->get("/seguimientos/{$validacion->id_seguimiento}/editar")
            ->assertDontSee('Nueva sesión')
            ->assertDontSee('>Sesiones<', false);

        $this->actingAs($this->admin)->get("/seguimientos/{$validacion->id_seguimiento}/sesiones/crear")
            ->assertRedirect(route('seguimientos.editar', $validacion))
            ->assertSessionHas('error', 'La fase Validación no registra sesiones.');

        $this->crearSesion(['seguimientos' => [$validacion->id_seguimiento]], $validacion)
            ->assertSessionHas('error', 'La fase Validación no registra sesiones.');

        $this->assertSame(0, Sesion::count());
    }

    public function test_onboarding_si_registra_sesiones(): void
    {
        $onboarding = Actividad::create(['id_empresa' => $this->empresa->id_empresa, 'id_bloque' => $this->bloque->id_bloque, 'tipo' => TipoActividad::Onboarding, 'nombre' => 'Inducción']);
        $seguimiento = $this->seguimiento($this->grupo1, 1, $onboarding);

        $this->crearSesion(['seguimientos' => [$seguimiento->id_seguimiento]], $seguimiento)->assertSessionHas('exito');
        $this->assertSame($seguimiento->id_seguimiento, Sesion::sole()->id_seguimiento);
    }

    // ===== Crear =====

    public function test_crea_la_sesion_con_su_asistencia(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');
        $luis = $this->participante($this->grupo1, 'Luis');
        $this->participante($this->grupo1, 'Baja', false);
        $this->participante($this->grupo2, 'Otro');

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertOk()
            ->assertSee('Conciliar cuentas · Transferencia')
            ->assertSee('Asistencia · Grupo 1')
            ->assertSee('Ana Prueba')
            ->assertDontSee('Baja Prueba')
            ->assertSee('Marcar todos')
            ->assertSee('Desmarcar todos')
            // Las casillas empiezan sin marcar.
            ->assertDontSee('data-asistio checked', false);

        $this->crearSesion([
            'comentarios' => 'Buena sesión',
            'asistencia' => [$this->seguimiento1->id_seguimiento => [
                $ana->id_participante => ['asistio' => '1', 'comentarios' => ' Llegó tarde '],
                $luis->id_participante => ['comentarios' => ''],
            ]],
        ])->assertRedirect(route('seguimientos.editar', $this->seguimiento1))
            ->assertSessionHas('exito', 'Se registró la sesión del 10/03/2027 en 1 grupo.');

        $sesion = Sesion::sole();
        $this->assertSame('2027-03-10', $sesion->fecha->format('Y-m-d'));
        $this->assertSame(Modalidad::Virtual, $sesion->modalidad);
        $this->assertSame('Buena sesión', $sesion->comentarios);

        // Un registro por participante activo del grupo: asistió sí o no.
        $this->assertSame(2, $sesion->asistencias()->count());
        $this->assertTrue(Asistencia::where('id_participante', $ana->id_participante)->sole()->asistio);
        $this->assertSame('Llegó tarde', Asistencia::where('id_participante', $ana->id_participante)->sole()->comentarios);
        $this->assertFalse(Asistencia::where('id_participante', $luis->id_participante)->sole()->asistio);
        $this->assertNull(Asistencia::where('id_participante', $luis->id_participante)->sole()->comentarios);

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/editar")
            ->assertSee('Conciliación bancaria')
            ->assertSee('1 de 2');
        $this->actingAs($this->admin)->get('/seguimientos')->assertSee('data-etiqueta="Sesiones">1<', false);
    }

    public function test_una_captura_crea_una_sesion_por_grupo_elegido(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');
        $eva = $this->participante($this->grupo2, 'Eva');
        $grupo3 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 3']);
        $seguimiento3 = $this->seguimiento($grupo3);
        // No se ofrecen: otra fase, y un grupo de baja.
        $this->seguimiento($this->grupo1, 3);
        $deBaja = $this->seguimiento(Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo de baja', 'estado' => false]));

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertSeeInOrder(['Grupo 1', 'Grupo 2', 'Grupo 3'])
            ->assertDontSee('Grupo de baja')
            ->assertSee('value="'.$this->seguimiento1->id_seguimiento.'" class="form-check-input" data-elegir-grupo checked', false)
            ->assertDontSee('value="'.$this->seguimiento2->id_seguimiento.'" class="form-check-input" data-elegir-grupo checked', false);

        $this->crearSesion([
            'seguimientos' => [$this->seguimiento1->id_seguimiento, $this->seguimiento2->id_seguimiento],
            'asistencia' => [
                $this->seguimiento1->id_seguimiento => [$ana->id_participante => ['asistio' => '1']],
                $this->seguimiento2->id_seguimiento => [$eva->id_participante => ['asistio' => '1']],
                // Grupo no elegido: se ignora.
                $seguimiento3->id_seguimiento => [999 => ['asistio' => '1']],
            ],
        ])->assertSessionHas('exito', 'Se registró la sesión del 10/03/2027 en 2 grupos.');

        $this->assertSame(1, $this->seguimiento1->sesiones()->count());
        $this->assertSame(1, $this->seguimiento2->sesiones()->count());
        $this->assertSame(0, $seguimiento3->sesiones()->count());
        $this->assertTrue($this->seguimiento1->sesiones()->sole()->asistencias()->sole()->asistio);
        $this->assertSame($eva->id_participante, $this->seguimiento2->sesiones()->sole()->asistencias()->sole()->id_participante);
        $this->assertSame(2, Asistencia::count());

        // Un grupo de baja o de otra fase no se puede elegir a mano.
        $this->crearSesion(['seguimientos' => [$deBaja->id_seguimiento]])
            ->assertSessionHasErrors(['seguimientos.0' => 'Elige grupos de la lista.']);
    }

    public function test_valida_los_datos(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');

        $this->crearSesion(['fecha' => '', 'tema' => '', 'modalidad' => 'Remota', 'seguimientos' => []])
            ->assertSessionHasErrors([
                'fecha' => 'Escribe la fecha de la sesión.',
                'tema' => 'Escribe el tema de la sesión.',
                'modalidad' => 'Elige una modalidad de la lista.',
                'seguimientos' => 'Elige al menos un grupo.',
            ]);

        $this->crearSesion([
            'tema' => str_repeat('t', 256),
            'asistencia' => [$this->seguimiento1->id_seguimiento => [$ana->id_participante => ['comentarios' => str_repeat('c', 10001)]]],
        ])->assertSessionHasErrors([
            'tema' => 'El tema no puede tener más de 255 caracteres.',
            "asistencia.{$this->seguimiento1->id_seguimiento}.{$ana->id_participante}.comentarios" => 'El comentario no puede tener más de 10,000 caracteres.',
        ]);

        $this->assertSame(0, Sesion::count());
    }

    public function test_un_grupo_sin_participantes_guarda_la_sesion_sin_asistencia(): void
    {
        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertSee('Este grupo no tiene participantes activos.');

        $this->crearSesion()->assertSessionHas('exito');
        $this->assertSame(0, Asistencia::count());
    }

    // ===== Fecha repetida =====

    public function test_avisa_si_ya_hay_una_sesion_con_la_misma_fecha(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');
        $this->crearSesion();

        $repetida = [
            'tema' => 'Otra sesión',
            'seguimientos' => [$this->seguimiento1->id_seguimiento, $this->seguimiento2->id_seguimiento],
            'asistencia' => [$this->seguimiento1->id_seguimiento => [$ana->id_participante => ['asistio' => '1']]],
        ];

        $this->crearSesion($repetida)
            ->assertRedirect(route('sesiones.crear', $this->seguimiento1))
            ->assertSessionHas('repetidas', ['Grupo 1']);

        // Nada se guardó y el formulario conserva lo capturado.
        $this->assertSame(1, Sesion::count());
        $this->actingAs($this->admin)->withSession(['repetidas' => ['Grupo 1'], '_old_input' => $this->datos($repetida)])
            ->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertSee('Ya hay una sesión con la fecha 10/03/2027 en: Grupo 1.')
            ->assertSee('Guardar de todos modos')
            ->assertSee('value="Otra sesión"', false)
            ->assertSee('data-asistio checked', false);

        $this->crearSesion([...$repetida, 'confirmar_repetida' => '1'])->assertSessionHas('exito');
        $this->assertSame(2, $this->seguimiento1->sesiones()->count());
        $this->assertSame(1, $this->seguimiento2->sesiones()->count());

        // Otra fecha no avisa.
        $this->crearSesion(['fecha' => '2027-03-11'])->assertSessionHas('exito');
    }

    // ===== Editar =====

    public function test_editar_corrige_la_sesion_y_su_asistencia(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');
        $this->crearSesion(['asistencia' => [$this->seguimiento1->id_seguimiento => [$ana->id_participante => ['asistio' => '1']]]]);
        $sesion = Sesion::sole();

        // Entra alguien nuevo al grupo y Ana se da de baja: los dos aparecen.
        $nuevo = $this->participante($this->grupo1, 'Nuevo');
        $ana->update(['estado' => false]);

        $this->actingAs($this->admin)->get("/sesiones/{$sesion->id_sesion}/editar")
            ->assertOk()
            ->assertSee('Editar sesión')
            ->assertSee('value="Conciliación bancaria"', false)
            ->assertSee('Ana Prueba')
            ->assertSee('(de baja)')
            ->assertSee('Nuevo Prueba')
            ->assertDontSee('data-elegir-grupo', false);

        $this->actingAs($this->admin)->put("/sesiones/{$sesion->id_sesion}", [
            'fecha' => '2027-03-12',
            'tema' => 'Tema corregido',
            'modalidad' => 'Híbrida',
            'asistencia' => [$this->seguimiento1->id_seguimiento => [
                $nuevo->id_participante => ['asistio' => '1'],
            ]],
        ])->assertRedirect(route('seguimientos.editar', $this->seguimiento1))
            ->assertSessionHas('exito', 'Se actualizó la sesión del 12/03/2027.');

        $sesion->refresh();
        $this->assertSame('Tema corregido', $sesion->tema);
        $this->assertSame($this->admin->id_usuario, $sesion->id_usuario); // autor de la última corrección
        $this->assertSame(Modalidad::Hibrida, $sesion->modalidad);
        $this->assertFalse(Asistencia::where('id_participante', $ana->id_participante)->sole()->asistio);
        $this->assertTrue(Asistencia::where('id_participante', $nuevo->id_participante)->sole()->asistio);
        $this->assertSame(2, Asistencia::count());
    }

    public function test_al_editar_avisa_si_la_fecha_ya_la_tiene_otra_sesion(): void
    {
        $this->crearSesion();
        $this->crearSesion(['fecha' => '2027-03-11']);
        $segunda = Sesion::whereDate('fecha', '2027-03-11')->sole();
        $url = "/sesiones/{$segunda->id_sesion}";

        // La misma fecha que ya tenía no avisa.
        $this->actingAs($this->admin)->put($url, $this->datos(['fecha' => '2027-03-11']))->assertSessionHas('exito');

        $this->actingAs($this->admin)->put($url, $this->datos())
            ->assertRedirect(route('sesiones.editar', $segunda))
            ->assertSessionHas('repetidas', ['Grupo 1']);
        $this->assertSame('2027-03-11', $segunda->fresh()->fecha->format('Y-m-d'));

        $this->actingAs($this->admin)->put($url, $this->datos(['confirmar_repetida' => '1']))->assertSessionHas('exito');
        $this->assertSame('2027-03-10', $segunda->fresh()->fecha->format('Y-m-d'));
    }

    public function test_las_de_baja_solo_se_consultan(): void
    {
        $ana = $this->participante($this->grupo1, 'Ana');
        $this->crearSesion(['asistencia' => [$this->seguimiento1->id_seguimiento => [$ana->id_participante => ['asistio' => '1']]]]);
        $sesion = Sesion::sole();
        $this->actividad->update(['estado' => false]);

        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/editar")
            ->assertDontSee('Nueva sesión')
            ->assertSee('Consultar');

        $this->actingAs($this->admin)->get("/sesiones/{$sesion->id_sesion}/editar")
            ->assertOk()
            ->assertSee('Consultar sesión')
            ->assertSee('<fieldset disabled', false)
            ->assertDontSee('>Guardar<', false);

        $this->actingAs($this->admin)->put("/sesiones/{$sesion->id_sesion}", $this->datos(['tema' => 'Cambio']))
            ->assertSessionHas('error', 'Esta sesión es de un grupo o una actividad dados de baja: solo se puede consultar.');
        $this->crearSesion()->assertSessionHas('error');

        $this->assertSame('Conciliación bancaria', $sesion->fresh()->tema);
        $this->assertSame(1, Sesion::count());
    }

    // ===== Fechas del seguimiento =====

    public function test_sin_fecha_de_inicio_y_fecha_fin_no_se_registran_sesiones(): void
    {
        foreach ([['fecha_inicio' => null], ['fecha_fin' => null], ['fecha_inicio' => null, 'fecha_fin' => null]] as $sinFechas) {
            $this->seguimiento1->update(['fecha_inicio' => '2027-03-01', 'fecha_fin' => '2027-03-31', ...$sinFechas]);

            $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/editar")
                ->assertSee('primero captura y guarda la fecha de inicio y la fecha fin')
                ->assertDontSee('Nueva sesión');

            $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
                ->assertRedirect(route('seguimientos.editar', $this->seguimiento1))
                ->assertSessionHas('error', 'Primero captura la fecha de inicio y la fecha fin del seguimiento: las sesiones deben quedar dentro de esas fechas.');

            $this->crearSesion()->assertSessionHas('error');
        }

        $this->assertSame(0, Sesion::count());
    }

    public function test_la_fecha_de_la_sesion_debe_estar_dentro_de_las_fechas_del_seguimiento(): void
    {
        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertSee('min="2027-03-01"', false)
            ->assertSee('max="2027-03-31"', false)
            ->assertSee('Debe estar entre las fechas del seguimiento: del 01/03/2027 al 31/03/2027.');

        foreach (['2027-02-28', '2027-04-01'] as $fuera) {
            $this->crearSesion(['fecha' => $fuera])
                ->assertSessionHasErrors(['fecha' => 'La fecha queda fuera de las fechas del seguimiento: Grupo 1 (del 01/03/2027 al 31/03/2027).']);
        }

        // Los extremos cuentan como dentro.
        $this->crearSesion(['fecha' => '2027-03-01'])->assertSessionHas('exito');
        $this->crearSesion(['fecha' => '2027-03-31'])->assertSessionHas('exito');
        $this->assertSame(2, Sesion::count());
    }

    public function test_con_varios_grupos_la_fecha_debe_caber_en_las_fechas_de_cada_uno(): void
    {
        $this->seguimiento2->update(['fecha_inicio' => '2027-03-15', 'fecha_fin' => '2027-04-15']);
        $grupo3 = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Grupo 3']);
        $sinFechas = $this->seguimiento($grupo3, 2, null, ['fecha_inicio' => null, 'fecha_fin' => null]);
        $ambos = [$this->seguimiento1->id_seguimiento, $this->seguimiento2->id_seguimiento];

        // El grupo sin fechas se ve, pero no se puede elegir.
        $this->actingAs($this->admin)->get("/seguimientos/{$this->seguimiento1->id_seguimiento}/sesiones/crear")
            ->assertSee('Del 15/03/2027 al 15/04/2027')
            ->assertSee('Sin fechas')
            ->assertSee('value="'.$sinFechas->id_seguimiento.'" class="form-check-input" data-elegir-grupo  disabled', false);

        $this->crearSesion(['seguimientos' => [$sinFechas->id_seguimiento]])
            ->assertSessionHasErrors(['seguimientos.0' => 'Elige grupos de la lista.']);

        // El 10 de marzo cabe en el Grupo 1, pero no en el Grupo 2.
        $this->crearSesion(['fecha' => '2027-03-10', 'seguimientos' => $ambos])
            ->assertSessionHasErrors(['fecha' => 'La fecha queda fuera de las fechas del seguimiento: Grupo 2 (del 15/03/2027 al 15/04/2027).']);
        $this->assertSame(0, Sesion::count());

        // El 20 de marzo cabe en los dos.
        $this->crearSesion(['fecha' => '2027-03-20', 'seguimientos' => $ambos])->assertSessionHas('exito');
        $this->assertSame(2, Sesion::count());
    }

    public function test_al_editar_la_fecha_tambien_debe_estar_dentro(): void
    {
        $this->crearSesion();
        $sesion = Sesion::sole();

        $this->actingAs($this->admin)->get("/sesiones/{$sesion->id_sesion}/editar")
            ->assertSee('min="2027-03-01"', false)
            ->assertSee('max="2027-03-31"', false);

        $this->actingAs($this->admin)->put("/sesiones/{$sesion->id_sesion}", $this->datos(['fecha' => '2027-04-02']))
            ->assertSessionHasErrors(['fecha' => 'La fecha queda fuera de las fechas del seguimiento: Grupo 1 (del 01/03/2027 al 31/03/2027).']);
        $this->assertSame('2027-03-10', $sesion->fresh()->fecha->format('Y-m-d'));
    }

    public function test_las_fechas_del_seguimiento_no_pueden_dejar_sesiones_fuera(): void
    {
        $this->crearSesion(['fecha' => '2027-03-05']);
        $this->crearSesion(['fecha' => '2027-03-25']);
        $url = "/seguimientos/{$this->seguimiento1->id_seguimiento}";
        $datos = ['estado_actividad' => 'En curso'];

        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '2027-03-10', 'fecha_fin' => '2027-03-20'])
            ->assertSessionHasErrors(['fecha_inicio' => 'Con estas fechas quedarían fuera sesiones ya registradas (05/03/2027, 25/03/2027). Elige fechas que las incluyan o corrige primero esas sesiones.']);

        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '', 'fecha_fin' => ''])
            ->assertSessionHasErrors(['fecha_inicio' => 'No se pueden quitar las fechas: el seguimiento ya tiene sesiones.']);

        $this->assertSame('2027-03-01', $this->seguimiento1->fresh()->fecha_inicio->format('Y-m-d'));

        // Fechas que siguen incluyendo las sesiones (los extremos cuentan) sí se guardan.
        $this->actingAs($this->admin)->put($url, [...$datos, 'fecha_inicio' => '2027-03-05', 'fecha_fin' => '2027-03-25'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-03-25', $this->seguimiento1->fresh()->fecha_fin->format('Y-m-d'));

        // Un seguimiento sin sesiones puede cambiar o quitar sus fechas.
        $this->actingAs($this->admin)->put("/seguimientos/{$this->seguimiento2->id_seguimiento}", [...$datos, 'fecha_inicio' => '', 'fecha_fin' => ''])
            ->assertSessionHasNoErrors();
    }
}
