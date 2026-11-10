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
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipo del proceso: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS son iguales en todos los seguimientos de un proceso.
 */
class EquipoDeProcesoTest extends TestCase
{
    use RefreshDatabase;

    private const EQUIPO = [
        'responsable' => 'Ana',
        'documentador' => 'Eva',
        'lider_transferencia' => 'Mario',
        'lider_transferencia_gbs' => 'Sofía',
    ];

    private int $contador = 0;

    private Empresa $empresa;

    private Proceso $proceso;

    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->proceso = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 1']);
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

    private function actividad(string $nombre, ?Proceso $proceso = null, TipoActividad $tipo = TipoActividad::Onboarding, bool $estado = true): Actividad
    {
        $proceso ??= $this->proceso;

        return Actividad::create(['id_empresa' => $proceso->id_empresa, 'id_proceso' => $proceso->id_proceso, 'tipo' => $tipo, 'nombre' => $nombre, 'estado' => $estado]);
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

    private function equipoDe(Seguimiento $seguimiento): array
    {
        return $seguimiento->fresh()->only(array_keys(self::EQUIPO));
    }

    private function url(?Proceso $proceso = null): string
    {
        return '/seguimientos/procesos/'.($proceso ?? $this->proceso)->id_proceso.'/equipo';
    }

    /** La lista de seguimientos filtrada por el proceso: ahí está la tarjeta del equipo. */
    private function lista(?Proceso $proceso = null): string
    {
        return '/seguimientos?id_proceso='.($proceso ?? $this->proceso)->id_proceso;
    }

    public function test_el_formulario_individual_no_repite_el_equipo_ni_lo_cambia(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'), null, 1, self::EQUIPO);
        $admin = $this->usuario();

        // Solo un enlace a la lista filtrada por su proceso, donde está el equipo.
        $this->actingAs($admin)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")
            ->assertDontSee('Equipo del proceso Tesorería')
            ->assertDontSee('Sofía')
            ->assertSee(route('seguimientos.index', ['id_proceso' => $this->proceso->id_proceso]).'#equipo', false)
            ->assertDontSee('name="responsable"', false)
            ->assertDontSee('name="lider_transferencia_gbs"', false)
            ->assertSee('name="experto"', false);

        $this->actingAs($admin)->put("/seguimientos/{$seguimiento->id_seguimiento}", [
            'estado_actividad' => 'En curso',
            'experto' => 'Experto propio',
            'responsable' => 'Otro',
            'documentador' => 'Otro',
            'lider_transferencia' => 'Otro',
            'lider_transferencia_gbs' => 'Otro',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::EQUIPO, $this->equipoDe($seguimiento));
        $this->assertSame('Experto propio', $seguimiento->fresh()->experto);
    }

    public function test_se_guarda_en_todos_los_seguimientos_del_proceso(): void
    {
        $transicion = $this->actividad('Transición', null, TipoActividad::Transicion);
        $grupoDeBaja = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'De baja', 'estado' => false]);
        $propios = [
            $this->seguimiento($this->actividad('Uno'), null, 1, ['experto' => 'Experto propio']),
            $this->seguimiento($transicion, null, 2),
            $this->seguimiento($transicion, $grupoDeBaja, 3),
            $this->seguimiento($this->actividad('Actividad de baja', null, TipoActividad::Onboarding, false)),
        ];
        $pagos = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $grupoPagos = Grupo::create(['id_proceso' => $pagos->id_proceso, 'nombre' => 'G']);
        $deOtroProceso = $this->seguimiento($this->actividad('De pagos', $pagos), $grupoPagos, 1, ['responsable' => 'No cambia']);
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->actingAs($consultor)->get($this->lista())
            ->assertOk()
            ->assertSee('Equipo del proceso Tesorería')
            ->assertSee('data-equipo-proceso', false)
            ->assertSee('Se actualizarán 4 seguimientos.');

        // Al guardar vuelve a la lista con sus filtros.
        $this->actingAs($consultor)->from($this->lista())->put($this->url(), self::EQUIPO)
            ->assertRedirect($this->lista())
            ->assertSessionHas('exito', 'Se guardó el equipo del proceso Tesorería. Se actualizaron 4 seguimientos.');

        foreach ($propios as $seguimiento) {
            $this->assertSame(self::EQUIPO, $this->equipoDe($seguimiento));
        }

        // El experto es individual y otro proceso no cambia. El equipo no cuenta como captura: no hay autor.
        $this->assertSame('Experto propio', $propios[0]->fresh()->experto);
        $this->assertNull($propios[0]->fresh()->id_usuario);
        $this->assertSame('No cambia', $deOtroProceso->fresh()->responsable);
        $this->assertNull($deOtroProceso->fresh()->documentador);

        // Un dato se puede vaciar.
        $this->actingAs($consultor)->put($this->url(), [...self::EQUIPO, 'responsable' => ''])->assertSessionHasNoErrors();
        $this->assertNull($propios[1]->fresh()->responsable);
    }

    public function test_valida_y_respeta_la_empresa(): void
    {
        $this->seguimiento($this->actividad('Uno'));
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        $procesoAjeno = Proceso::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Ajeno']);
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->put($this->url(), self::EQUIPO)->assertRedirect(route('login'));

        $this->actingAs($consultor)->put($this->url(), ['lider_transferencia' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['lider_transferencia' => 'Este campo no puede tener más de 255 caracteres.']);

        // Un proceso ajeno en el filtro se ignora: no aparece su equipo.
        $this->actingAs($consultor)->get($this->lista($procesoAjeno))->assertOk()->assertDontSee('Equipo del proceso Ajeno');
        $this->actingAs($consultor)->put($this->url($procesoAjeno), self::EQUIPO)->assertNotFound();
    }

    public function test_sin_seguimientos_o_con_el_proceso_de_baja_no_se_cambia(): void
    {
        $vacio = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Vacío']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get($this->lista($vacio))
            ->assertSee('Este proceso todavía no tiene seguimientos')
            ->assertDontSee('data-equipo-proceso', false)
            ->assertDontSee('>Guardar<', false);
        $this->actingAs($admin)->from('/seguimientos')->put($this->url($vacio), self::EQUIPO)
            ->assertSessionHas('error', 'El proceso Vacío todavía no tiene seguimientos.');

        $seguimiento = $this->seguimiento($this->actividad('Uno'), null, 1, ['responsable' => 'Original']);
        $this->proceso->update(['estado' => false]);

        $this->actingAs($admin)->get($this->lista())
            ->assertSee('El proceso está dado de baja')
            ->assertSee('value="Original"', false)
            ->assertDontSee('data-equipo-proceso', false)
            ->assertDontSee('>Guardar<', false);
        $this->actingAs($admin)->from('/seguimientos')->put($this->url(), self::EQUIPO)
            ->assertSessionHas('error', 'El proceso Tesorería está dado de baja: su equipo no se puede cambiar.');
        $this->assertSame('Original', $seguimiento->fresh()->responsable);
    }

    public function test_avisa_si_el_proceso_tiene_valores_distintos(): void
    {
        $this->seguimiento($this->actividad('Uno'), null, 1, ['responsable' => 'Ana', 'documentador' => 'Eva']);
        $this->seguimiento($this->actividad('Dos'), null, 1, ['responsable' => 'Luis', 'documentador' => 'Eva']);

        // Muestra el más reciente.
        $this->actingAs($this->usuario())->get($this->lista())
            ->assertSee('Hay valores distintos en: Responsable.')
            ->assertSee('value="Luis"', false);
    }

    public function test_los_seguimientos_nuevos_copian_el_equipo_del_proceso(): void
    {
        $this->seguimiento($this->actividad('Existente'), null, 1, [...self::EQUIPO, 'experto' => 'No se copia']);
        $admin = $this->usuario();

        // Un grupo nuevo en el proceso.
        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Nuevo'])->assertSessionHas('exito');
        $nuevo = Grupo::where('nombre', 'Nuevo')->sole()->seguimientos()->sole();
        $this->assertSame(self::EQUIPO, $nuevo->only(array_keys(self::EQUIPO)));
        $this->assertNull($nuevo->experto);

        // Una actividad nueva en el proceso.
        $this->actingAs($admin)->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Transición',
            'id_proceso' => $this->proceso->id_proceso,
            'nombre' => 'Nueva',
        ])->assertSessionHas('exito');

        $deLaNueva = Actividad::where('nombre', 'Nueva')->sole()->seguimientos;
        $this->assertCount(6, $deLaNueva); // 2 grupos × 3 fases
        foreach ($deLaNueva as $seguimiento) {
            $this->assertSame(self::EQUIPO, $seguimiento->only(array_keys(self::EQUIPO)));
        }

        // Un proceso sin seguimientos genera con el equipo vacío.
        $pagos = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $this->actividad('De pagos', $pagos);
        $this->actingAs($admin)->post('/grupos', ['id_proceso' => $pagos->id_proceso, 'nombre' => 'G pagos']);
        $this->assertNull(Grupo::where('nombre', 'G pagos')->sole()->seguimientos()->sole()->responsable);
    }

    public function test_la_lista_muestra_el_equipo_solo_al_filtrar_por_proceso(): void
    {
        $this->seguimiento($this->actividad('Uno'), null, 1, self::EQUIPO);
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/seguimientos')->assertDontSee('Equipo del proceso');
        $this->actingAs($admin)->get($this->lista())
            ->assertSee('Equipo del proceso Tesorería')
            ->assertSee('value="Sofía"', false)
            ->assertSee('data-original="Sofía"', false)
            ->assertSee(route('seguimientos.equipo.actualizar', $this->proceso), false)
            ->assertDontSee('data-abierto', false);

        // Si el guardado falla, la tarjeta vuelve abierta con lo que se escribió.
        $this->actingAs($admin)->from($this->lista())
            ->put($this->url(), [...self::EQUIPO, 'responsable' => str_repeat('a', 256)])
            ->assertRedirect($this->lista());
        $this->actingAs($admin)->get($this->lista())
            ->assertSee('data-abierto', false)
            ->assertSee('Este campo no puede tener más de 255 caracteres.');
    }
}
