<?php

namespace Tests\Feature;

use App\Enums\EstadoAcceso;
use App\Enums\RolApp;
use App\Models\AccesoSolicitado;
use App\Models\Aplicativo;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accesos solicitados: registro para varios participantes, una sola solicitud en curso
 * por participante y aplicativo, fecha otorgado y permisos por empresa.
 */
class AccesoSolicitadoTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Proceso $proceso;

    private Grupo $grupo;

    private Aplicativo $sap;

    private Usuario $admin;

    private Usuario $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->proceso = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 1']);
        $this->sap = Aplicativo::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'SAP']);
        $this->admin = $this->usuario(RolApp::Admin);
        $this->consultor = $this->usuario(RolApp::Consultor, $this->empresa);
    }

    private function usuario(RolApp $rol, ?Empresa $empresa = null): Usuario
    {
        $this->contador++;

        return $this->asignarEmpresa(Usuario::create([
            'nombre' => 'Prueba',
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]), $empresa);
    }

    private function participante(string $nombre, ?Grupo $grupo = null, bool $estado = true): Participante
    {
        return Participante::create([
            'id_grupo' => ($grupo ?? $this->grupo)->id_grupo,
            'nombre' => $nombre,
            'apellidos' => 'Gómez',
            'correo' => mb_strtolower($nombre).'@empresa.com',
            'rol' => 'Analista',
            'estado' => $estado,
        ]);
    }

    private function acceso(Participante $participante, EstadoAcceso $estado = EstadoAcceso::Solicitado, ?Aplicativo $aplicativo = null): AccesoSolicitado
    {
        return AccesoSolicitado::create([
            'id_participante' => $participante->id_participante,
            'id_aplicativo' => ($aplicativo ?? $this->sap)->id_aplicativo,
            'fecha_solicitud' => '2026-10-01',
            'fecha_otorgado' => $estado === EstadoAcceso::Otorgado ? '2026-10-05' : null,
            'estado' => $estado,
        ]);
    }

    /** @param  list<Participante>  $participantes */
    private function registrar(array $participantes, array $cambios = [], ?Usuario $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->consultor)->post('/accesos', [
            'id_empresa' => $this->empresa->id_empresa,
            'id_aplicativo' => $this->sap->id_aplicativo,
            'fecha_solicitud' => '2026-11-02',
            'participantes' => array_map(fn (Participante $p) => $p->id_participante, $participantes),
            ...$cambios,
        ]);
    }

    private function editar(AccesoSolicitado $acceso, array $datos, ?Usuario $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->consultor)->put("/accesos/{$acceso->id_acceso_solicitado}", [
            'estado' => $acceso->estado->value,
            'fecha_solicitud' => $acceso->fecha_solicitud->toDateString(),
            ...$datos,
        ]);
    }

    // ===== Registrar =====

    public function test_registra_una_solicitud_por_participante_elegido(): void
    {
        $ana = $this->participante('Ana');
        $luis = $this->participante('Luis');

        $this->registrar([$ana, $luis], ['comentarios' => 'Perfil consulta'])
            ->assertRedirect(route('accesos.index', ['id_aplicativo' => $this->sap->id_aplicativo]))
            ->assertSessionHas('exito', 'Se registraron 2 solicitudes de SAP: Ana Gómez y Luis Gómez.')
            ->assertSessionMissing('aviso');

        $this->assertSame(2, AccesoSolicitado::count());

        foreach (AccesoSolicitado::all() as $acceso) {
            $this->assertSame(EstadoAcceso::Solicitado, $acceso->estado);
            $this->assertSame('2026-11-02', $acceso->fecha_solicitud->toDateString());
            $this->assertNull($acceso->fecha_otorgado);
            $this->assertSame('Perfil consulta', $acceso->comentarios);
        }
    }

    public function test_omite_a_quien_ya_tiene_una_solicitud_en_curso_salvo_rechazada(): void
    {
        $ana = $this->participante('Ana');
        $luis = $this->participante('Luis');
        $carla = $this->participante('Carla');
        $dario = $this->participante('Dario');
        $this->acceso($ana, EstadoAcceso::EnTramite);
        $this->acceso($luis, EstadoAcceso::Otorgado);
        $this->acceso($carla, EstadoAcceso::Rechazado);
        // Una solicitud de otro aplicativo no cuenta.
        $this->acceso($dario, EstadoAcceso::Solicitado, Aplicativo::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Correo']));

        $this->registrar([$ana, $luis, $carla, $dario])
            ->assertSessionHas('exito', 'Se registraron 2 solicitudes de SAP: Carla Gómez y Dario Gómez.')
            ->assertSessionHas('aviso', 'Se omitieron 2 porque ya tienen una solicitud en curso de SAP: Ana Gómez y Luis Gómez.');

        $this->assertSame(2, AccesoSolicitado::where('id_aplicativo', $this->sap->id_aplicativo)->where('estado', 'Solicitado')->count());
    }

    public function test_si_todos_ya_la_tienen_solo_avisa(): void
    {
        $ana = $this->participante('Ana');
        $this->acceso($ana);

        $this->registrar([$ana])
            ->assertSessionMissing('exito')
            ->assertSessionHas('aviso', 'Se omitió 1 porque ya tiene una solicitud en curso de SAP: Ana Gómez.');

        $this->assertSame(1, AccesoSolicitado::count());
    }

    public function test_valida_al_registrar(): void
    {
        $ana = $this->participante('Ana');
        $deBaja = $this->participante('Baja', null, false);
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        $correoAjeno = Aplicativo::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Ajeno']);
        $sapDeBaja = Aplicativo::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Viejo', 'estado' => false]);

        $this->registrar([])->assertSessionHasErrors(['participantes' => 'Elige al menos un participante.']);
        $this->registrar([$deBaja])->assertSessionHasErrors(['participantes.0' => 'Elige participantes activos de la empresa.']);
        $this->registrar([$ana], ['id_aplicativo' => $correoAjeno->id_aplicativo])
            ->assertSessionHasErrors(['id_aplicativo' => 'Elige un aplicativo activo de la empresa.']);
        $this->registrar([$ana], ['id_aplicativo' => $sapDeBaja->id_aplicativo])
            ->assertSessionHasErrors(['id_aplicativo' => 'Elige un aplicativo activo de la empresa.']);
        $this->registrar([$ana], ['fecha_solicitud' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors(['fecha_solicitud' => 'La fecha de solicitud no puede ser futura.']);

        $this->assertSame(0, AccesoSolicitado::count());
    }

    public function test_la_pantalla_filtra_por_proceso_y_grupo(): void
    {
        $otro = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 2']);
        $this->participante('Ana');
        $this->participante('Bruno', $otro);
        $this->participante('Baja', null, false);

        $this->actingAs($this->consultor)->get('/accesos/crear')
            ->assertOk()
            ->assertSee('Ana Gómez')
            ->assertSee('Bruno Gómez')
            ->assertDontSee('Baja Gómez')
            ->assertSee('SAP');

        $this->actingAs($this->consultor)->get("/accesos/crear?id_grupo={$otro->id_grupo}")
            ->assertSee('Bruno Gómez')
            ->assertDontSee('Ana Gómez');
    }

    public function test_el_admin_elige_primero_la_empresa(): void
    {
        $this->participante('Ana');
        Empresa::create(['nombre' => 'Empresa Dos', 'sigla' => 'DOS']);

        $this->actingAs($this->admin)->get('/accesos/crear')
            ->assertSee('Elige la empresa.')
            ->assertDontSee('Ana Gómez');

        $this->actingAs($this->admin)->get("/accesos/crear?id_empresa={$this->empresa->id_empresa}")
            ->assertSee('Ana Gómez');

        $this->registrar([$this->participante('Luis')], [], $this->admin)->assertSessionHas('exito');
    }

    // ===== Editar =====

    public function test_otorgado_exige_fecha_que_no_sea_anterior_a_la_solicitud(): void
    {
        $acceso = $this->acceso($this->participante('Ana'));

        // Al editar, la fecha otorgado viene con la de hoy y solo se ve en Otorgado.
        $html = $this->actingAs($this->consultor)->get("/accesos/{$acceso->id_acceso_solicitado}/editar")->getContent();
        $this->assertStringContainsString('value="'.today()->format('Y-m-d').'"', $html);
        $this->assertMatchesRegularExpression('/data-solo-otorgado\s+hidden/', $html);

        $this->editar($acceso, ['estado' => 'Otorgado', 'fecha_otorgado' => ''])
            ->assertSessionHasErrors(['fecha_otorgado' => 'Escribe la fecha en que se otorgó.']);
        $this->editar($acceso, ['estado' => 'Otorgado', 'fecha_otorgado' => '2026-09-30'])
            ->assertSessionHasErrors(['fecha_otorgado' => 'La fecha otorgado no puede ser anterior a la fecha de solicitud.']);
        $this->editar($acceso, ['estado' => 'Otorgado', 'fecha_otorgado' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors(['fecha_otorgado' => 'La fecha otorgado no puede ser futura.']);
        $this->assertSame(EstadoAcceso::Solicitado, $acceso->fresh()->estado);

        // El mismo día de la solicitud sí vale.
        $this->editar($acceso, ['estado' => 'Otorgado', 'fecha_otorgado' => '2026-10-01'])->assertSessionHas('exito');
        $this->assertSame(EstadoAcceso::Otorgado, $acceso->fresh()->estado);
        $this->assertSame('2026-10-01', $acceso->fresh()->fecha_otorgado->toDateString());
    }

    public function test_al_salir_de_otorgado_se_vacia_la_fecha(): void
    {
        $acceso = $this->acceso($this->participante('Ana'), EstadoAcceso::Otorgado);

        // Aunque llegue una fecha, en otro estado se guarda vacía. Los cambios de estado son libres.
        $this->editar($acceso, ['estado' => 'Solicitado', 'fecha_otorgado' => '2026-10-05'])->assertSessionHas('exito');

        $this->assertSame(EstadoAcceso::Solicitado, $acceso->fresh()->estado);
        $this->assertNull($acceso->fresh()->fecha_otorgado);
    }

    public function test_no_se_reabre_una_rechazada_si_ya_hay_otra_en_curso(): void
    {
        $ana = $this->participante('Ana');
        $rechazada = $this->acceso($ana, EstadoAcceso::Rechazado);
        $this->acceso($ana, EstadoAcceso::EnTramite);

        $this->editar($rechazada, ['estado' => 'Solicitado'])
            ->assertSessionHasErrors(['estado' => 'Ana Gómez ya tiene otra solicitud en curso de SAP.']);
        $this->assertSame(EstadoAcceso::Rechazado, $rechazada->fresh()->estado);

        // Sí se pueden cambiar sus comentarios mientras siga Rechazada.
        $this->editar($rechazada, ['comentarios' => 'Sin licencias'])->assertSessionHas('exito');
        $this->assertSame('Sin licencias', $rechazada->fresh()->comentarios);
    }

    public function test_si_el_participante_o_el_aplicativo_estan_de_baja_solo_se_consulta(): void
    {
        $acceso = $this->acceso($this->participante('Ana'));
        $acceso->participante->update(['estado' => false]);

        $this->actingAs($this->consultor)->get("/accesos/{$acceso->id_acceso_solicitado}/editar")
            ->assertSee('solo se puede consultar')
            ->assertDontSee('>Guardar<', false);

        $this->editar($acceso, ['estado' => 'Rechazado'])->assertSessionHas('error');
        $this->assertSame(EstadoAcceso::Solicitado, $acceso->fresh()->estado);
    }

    // ===== Lista y permisos =====

    public function test_la_lista_cuenta_por_estado_y_filtra(): void
    {
        $ana = $this->participante('Ana');
        $luis = $this->participante('Luis');
        $this->acceso($ana);
        $this->acceso($luis, EstadoAcceso::Otorgado);

        $this->actingAs($this->consultor)->get('/accesos')
            ->assertOk()
            ->assertSee('Todas (2)')
            ->assertSee('Solicitado (1)')
            ->assertSee('Otorgado (1)')
            ->assertSee('Rechazado (0)');

        $this->actingAs($this->consultor)->get('/accesos?estado=Otorgado')
            ->assertSee('Luis Gómez')
            ->assertDontSee('Ana Gómez');

        // Desde la lista de participantes se llega con la persona ya elegida.
        $this->actingAs($this->consultor)->get('/participantes')
            ->assertSee(route('accesos.index', ['id_participante' => $ana->id_participante]), false);
        $this->actingAs($this->consultor)->get("/accesos?id_participante={$ana->id_participante}")
            ->assertSee('Solicitudes de Ana Gómez.')
            ->assertDontSee('Luis Gómez');
    }

    public function test_el_consultor_solo_trabaja_con_su_empresa(): void
    {
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        $procesoAjeno = Proceso::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Pagos']);
        $grupoAjeno = Grupo::create(['id_proceso' => $procesoAjeno->id_proceso, 'nombre' => 'G']);
        $ajeno = $this->participante('Ajeno', $grupoAjeno);
        $accesoAjeno = $this->acceso($ajeno, EstadoAcceso::Solicitado, Aplicativo::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'ERP']));

        $this->actingAs($this->consultor)->get('/accesos')->assertDontSee('Ajeno Gómez');
        $this->actingAs($this->consultor)->get("/accesos/{$accesoAjeno->id_acceso_solicitado}/editar")->assertNotFound();
        $this->editar($accesoAjeno, ['estado' => 'Rechazado'])->assertNotFound();

        // No puede registrar para participantes de otra empresa, aunque manipule el formulario.
        $this->registrar([$ajeno])->assertSessionHasErrors('participantes.0');
        $this->actingAs($this->consultor)->post('/accesos', [
            'id_empresa' => $ajena->id_empresa,
            'id_aplicativo' => $accesoAjeno->id_aplicativo,
            'fecha_solicitud' => '2026-11-02',
            'participantes' => [$ajeno->id_participante],
        ])->assertSessionHasErrors('id_empresa');

        $this->assertSame(1, AccesoSolicitado::count());
        $this->assertSame(EstadoAcceso::Solicitado, $accesoAjeno->fresh()->estado);

        // Sin asignación activa no hay con qué empresa registrar.
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/accesos/crear')
            ->assertRedirect(route('accesos.index'))
            ->assertSessionHas('error', 'No hay empresas activas para registrar accesos.');
    }

    public function test_aparece_en_el_menu(): void
    {
        $this->actingAs($this->consultor)->get('/')->assertSee('href="'.route('accesos.index').'"', false);
    }
}
