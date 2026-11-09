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
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipo del bloque: responsable, documentador, líder de transferencia y líder de
 * transferencia GBS son iguales en todos los seguimientos de un bloque.
 */
class EquipoDeBloqueTest extends TestCase
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

    private function equipoDe(Seguimiento $seguimiento): array
    {
        return $seguimiento->fresh()->only(array_keys(self::EQUIPO));
    }

    private function url(?Bloque $bloque = null): string
    {
        return '/seguimientos/bloques/'.($bloque ?? $this->bloque)->id_bloque.'/equipo';
    }

    public function test_el_formulario_individual_muestra_el_equipo_pero_no_lo_cambia(): void
    {
        $seguimiento = $this->seguimiento($this->actividad('Uno'), null, 1, self::EQUIPO);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")
            ->assertSee('Equipo del bloque Tesorería')
            ->assertSee('Sofía')
            ->assertSee(route('seguimientos.equipo', $this->bloque), false)
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

    public function test_se_guarda_en_todos_los_seguimientos_del_bloque(): void
    {
        $transicion = $this->actividad('Transición', null, TipoActividad::Transicion);
        $grupoDeBaja = Grupo::create(['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'De baja', 'estado' => false]);
        $propios = [
            $this->seguimiento($this->actividad('Uno'), null, 1, ['experto' => 'Experto propio']),
            $this->seguimiento($transicion, null, 2),
            $this->seguimiento($transicion, $grupoDeBaja, 3),
            $this->seguimiento($this->actividad('Actividad de baja', null, TipoActividad::Onboarding, false)),
        ];
        $pagos = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $grupoPagos = Grupo::create(['id_bloque' => $pagos->id_bloque, 'nombre' => 'G']);
        $deOtroBloque = $this->seguimiento($this->actividad('De pagos', $pagos), $grupoPagos, 1, ['responsable' => 'No cambia']);
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->actingAs($consultor)->get($this->url())
            ->assertOk()
            ->assertSee('Equipo del bloque Tesorería')
            ->assertSee('Al guardar se actualizan los 4 seguimientos del bloque');

        $this->actingAs($consultor)->put($this->url(), self::EQUIPO)
            ->assertRedirect(route('seguimientos.equipo', $this->bloque))
            ->assertSessionHas('exito', 'Se guardó el equipo del bloque Tesorería. Se actualizaron 4 seguimientos.');

        foreach ($propios as $seguimiento) {
            $this->assertSame(self::EQUIPO, $this->equipoDe($seguimiento));
        }

        // El experto es individual y otro bloque no cambia. El equipo no cuenta como captura: no hay autor.
        $this->assertSame('Experto propio', $propios[0]->fresh()->experto);
        $this->assertNull($propios[0]->fresh()->id_usuario);
        $this->assertSame('No cambia', $deOtroBloque->fresh()->responsable);
        $this->assertNull($deOtroBloque->fresh()->documentador);

        // Un dato se puede vaciar.
        $this->actingAs($consultor)->put($this->url(), [...self::EQUIPO, 'responsable' => ''])->assertSessionHasNoErrors();
        $this->assertNull($propios[1]->fresh()->responsable);
    }

    public function test_valida_y_respeta_la_empresa(): void
    {
        $this->seguimiento($this->actividad('Uno'));
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        $bloqueAjeno = Bloque::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Ajeno']);
        $consultor = $this->usuario(RolApp::Consultor, $this->empresa);

        $this->get($this->url())->assertRedirect(route('login'));

        $this->actingAs($consultor)->put($this->url(), ['lider_transferencia' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['lider_transferencia' => 'Este campo no puede tener más de 255 caracteres.']);

        $this->actingAs($consultor)->get($this->url($bloqueAjeno))->assertNotFound();
        $this->actingAs($consultor)->put($this->url($bloqueAjeno), self::EQUIPO)->assertNotFound();
    }

    public function test_sin_seguimientos_o_con_el_bloque_de_baja_no_se_cambia(): void
    {
        $vacio = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Vacío']);
        $admin = $this->usuario();

        $this->actingAs($admin)->get($this->url($vacio))
            ->assertSee('todavía no tiene seguimientos')
            ->assertDontSee('>Guardar<', false);
        $this->actingAs($admin)->from('/seguimientos')->put($this->url($vacio), self::EQUIPO)
            ->assertSessionHas('error', 'El bloque Vacío todavía no tiene seguimientos: el equipo se captura cuando existan.');

        $seguimiento = $this->seguimiento($this->actividad('Uno'), null, 1, ['responsable' => 'Original']);
        $this->bloque->update(['estado' => false]);

        $this->actingAs($admin)->get($this->url())
            ->assertSee('El bloque está dado de baja')
            ->assertSee('value="Original"', false)
            ->assertDontSee('>Guardar<', false);
        $this->actingAs($admin)->from('/seguimientos')->put($this->url(), self::EQUIPO)
            ->assertSessionHas('error', 'El bloque Tesorería está dado de baja: su equipo no se puede cambiar.');
        $this->assertSame('Original', $seguimiento->fresh()->responsable);
    }

    public function test_avisa_si_el_bloque_tiene_valores_distintos(): void
    {
        $this->seguimiento($this->actividad('Uno'), null, 1, ['responsable' => 'Ana', 'documentador' => 'Eva']);
        $this->seguimiento($this->actividad('Dos'), null, 1, ['responsable' => 'Luis', 'documentador' => 'Eva']);

        // Muestra el más reciente.
        $this->actingAs($this->usuario())->get($this->url())
            ->assertSee('Hay seguimientos de este bloque con valores distintos en: Responsable.')
            ->assertSee('value="Luis"', false);
    }

    public function test_los_seguimientos_nuevos_copian_el_equipo_del_bloque(): void
    {
        $this->seguimiento($this->actividad('Existente'), null, 1, [...self::EQUIPO, 'experto' => 'No se copia']);
        $admin = $this->usuario();

        // Un grupo nuevo en el bloque.
        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $this->bloque->id_bloque, 'nombre' => 'Nuevo'])->assertSessionHas('exito');
        $nuevo = Grupo::where('nombre', 'Nuevo')->sole()->seguimientos()->sole();
        $this->assertSame(self::EQUIPO, $nuevo->only(array_keys(self::EQUIPO)));
        $this->assertNull($nuevo->experto);

        // Una actividad nueva en el bloque.
        $this->actingAs($admin)->post('/actividades', [
            'accion' => 'confirmar',
            'id_empresa' => $this->empresa->id_empresa,
            'tipo' => 'Transición',
            'id_bloque' => $this->bloque->id_bloque,
            'nombre' => 'Nueva',
        ])->assertSessionHas('exito');

        $deLaNueva = Actividad::where('nombre', 'Nueva')->sole()->seguimientos;
        $this->assertCount(6, $deLaNueva); // 2 grupos × 3 fases
        foreach ($deLaNueva as $seguimiento) {
            $this->assertSame(self::EQUIPO, $seguimiento->only(array_keys(self::EQUIPO)));
        }

        // Un bloque sin seguimientos genera con el equipo vacío.
        $pagos = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $this->actividad('De pagos', $pagos);
        $this->actingAs($admin)->post('/grupos', ['id_bloque' => $pagos->id_bloque, 'nombre' => 'G pagos']);
        $this->assertNull(Grupo::where('nombre', 'G pagos')->sole()->seguimientos()->sole()->responsable);
    }

    public function test_la_lista_ofrece_el_equipo_al_filtrar_por_bloque(): void
    {
        $this->seguimiento($this->actividad('Uno'));
        $admin = $this->usuario();

        $this->actingAs($admin)->get('/seguimientos')->assertDontSee(route('seguimientos.equipo', $this->bloque), false);
        $this->actingAs($admin)->get("/seguimientos?id_bloque={$this->bloque->id_bloque}")
            ->assertSee(route('seguimientos.equipo', $this->bloque), false);
    }
}
