<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de participantes: los administran el admin y el consultor; cada uno ve los de su empresa.
 */
class ParticipanteTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Proceso $proceso;

    private Grupo $grupo;

    private Usuario $admin;

    private Usuario $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->proceso = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 1']);
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

    private function participante(?Grupo $grupo = null, string $nombre = 'Ana', array $extra = []): Participante
    {
        return Participante::create([
            'id_grupo' => ($grupo ?? $this->grupo)->id_grupo,
            'nombre' => $nombre,
            'apellidos' => 'Gómez',
            'correo' => mb_strtolower($nombre).'@empresa.com',
            'rol' => 'Analista',
            ...$extra,
        ]);
    }

    /** Otra empresa con su proceso y grupo. */
    private function grupoAjeno(): Grupo
    {
        $ajena = Empresa::create(['nombre' => 'Empresa Ajena', 'sigla' => 'AJE']);
        $proceso = Proceso::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Pagos']);

        return Grupo::create(['id_proceso' => $proceso->id_proceso, 'nombre' => 'Grupo ajeno']);
    }

    private function datos(array $cambios = []): array
    {
        return [
            'nombre' => 'Luis',
            'apellidos' => 'Pérez',
            'correo' => 'luis@empresa.com',
            'rol' => 'Analista',
            'id_grupo' => $this->grupo->id_grupo,
            'fecha_ingreso' => '2027-01-15',
            'comentarios' => '',
            ...$cambios,
        ];
    }

    // ===== Permisos y empresa =====

    public function test_el_consultor_administra_los_participantes_de_su_empresa(): void
    {
        $ana = $this->participante();
        $url = "/participantes/{$ana->id_participante}";

        $this->actingAs($this->consultor)->get('/participantes')
            ->assertOk()
            ->assertSee('Gómez, Ana')
            ->assertSee('Nuevo participante')
            ->assertSee('Dar de baja');

        $this->actingAs($this->consultor)->get('/participantes/crear')->assertOk();
        $this->actingAs($this->consultor)->post('/participantes', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($this->consultor)->get("{$url}/editar")->assertOk();
        $this->actingAs($this->consultor)->put($url, $this->datos(['correo' => 'ana@empresa.com', 'nombre' => 'Ana María']))->assertSessionHasNoErrors();
        $this->actingAs($this->consultor)->patch("{$url}/baja")->assertSessionHas('exito');
        $this->assertFalse($ana->fresh()->estado);
        $this->actingAs($this->consultor)->patch("{$url}/reactivar")->assertSessionHas('exito');

        $this->assertTrue($ana->fresh()->estado);
        $this->assertSame('Ana María', $ana->fresh()->nombre);
        $this->assertSame(2, Participante::count());
    }

    public function test_nadie_ve_ni_cambia_participantes_de_otra_empresa(): void
    {
        $ajeno = $this->participante($this->grupoAjeno(), 'Ajeno');
        $this->participante(null, 'Propio');
        $url = "/participantes/{$ajeno->id_participante}";

        $this->actingAs($this->consultor)->get('/participantes')
            ->assertSee('Gómez, Propio')
            ->assertDontSee('Gómez, Ajeno')
            ->assertDontSee('Todas las empresas');

        $this->actingAs($this->consultor)->get("{$url}/editar")->assertNotFound();
        $this->actingAs($this->consultor)->put($url, $this->datos())->assertNotFound();
        $this->actingAs($this->consultor)->patch("{$url}/baja")->assertNotFound();

        // Tampoco puede crear en un grupo ajeno.
        $this->actingAs($this->consultor)->post('/participantes', $this->datos(['id_grupo' => $ajeno->id_grupo]))
            ->assertSessionHasErrors(['id_grupo' => 'Elige un grupo activo de la lista.']);

        // Sin asignación activa no ve a nadie.
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/participantes')
            ->assertDontSee('Gómez, Propio')
            ->assertDontSee('Gómez, Ajeno');

        // El admin ve a todos, con la sigla de la empresa.
        $this->actingAs($this->admin)->get('/participantes')
            ->assertSee('Gómez, Propio')
            ->assertSee('Gómez, Ajeno')
            ->assertSee('(AJE)');

        $this->assertTrue($ajeno->fresh()->estado);
    }

    // ===== Lista =====

    public function test_filtra_por_grupo_y_busca_por_nombre_o_correo(): void
    {
        $otro = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'Grupo 2']);
        $this->participante(null, 'Ana');
        $this->participante($otro, 'Bruno');
        $this->participante(null, 'Carla', ['estado' => false]);

        $this->actingAs($this->consultor)->get("/participantes?id_grupo={$otro->id_grupo}")
            ->assertSee('Gómez, Bruno')
            ->assertDontSee('Gómez, Ana');

        $this->actingAs($this->consultor)->get('/participantes?buscar=bruno@')
            ->assertSee('Gómez, Bruno')
            ->assertDontSee('Gómez, Ana');

        $this->actingAs($this->consultor)->get('/participantes')->assertDontSee('Gómez, Carla');
        $this->actingAs($this->consultor)->get('/participantes?ver_bajas=1')->assertSee('Gómez, Carla')->assertSee('Reactivar');
    }

    // ===== Crear =====

    public function test_crear_limpia_el_texto_y_acepta_fecha_futura(): void
    {
        $this->actingAs($this->consultor)->get('/participantes/crear')
            ->assertOk()
            ->assertSee('Tesorería › Grupo 1');

        $this->actingAs($this->consultor)->post('/participantes', $this->datos([
            'nombre' => '  Luis   Alberto ',
            'correo' => ' LUIS@Empresa.COM ',
            'fecha_ingreso' => today()->addMonth()->toDateString(),
        ]))->assertRedirect(route('participantes.index'))
            ->assertSessionHas('exito', 'Se agregó a Luis Alberto Pérez.');

        $luis = Participante::sole();
        $this->assertSame('Luis Alberto', $luis->nombre);
        $this->assertSame('luis@empresa.com', $luis->correo);
        $this->assertSame(today()->addMonth()->toDateString(), $luis->fecha_ingreso->toDateString());
        $this->assertNull($luis->comentarios);
        $this->assertTrue($luis->estado);
    }

    public function test_valida_los_datos(): void
    {
        $this->participante($this->grupoAjeno(), 'Repetido', ['estado' => false]);
        $grupoDeBaja = Grupo::create(['id_proceso' => $this->proceso->id_proceso, 'nombre' => 'De baja', 'estado' => false]);

        $casos = [
            'correo' => [['correo' => 'REPETIDO@empresa.com'], 'Ya hay un participante con este correo (puede estar dado de baja o en otra empresa).'],
            'rol' => [['rol' => str_repeat('a', 51)], 'El rol no puede tener más de 50 caracteres.'],
            'apellidos' => [['apellidos' => '   '], 'Escribe los apellidos.'],
            'id_grupo' => [['id_grupo' => $grupoDeBaja->id_grupo], 'Elige un grupo activo de la lista.'],
            'fecha_ingreso' => [['fecha_ingreso' => '2027-02-30'], 'Escribe una fecha válida.'],
        ];

        foreach ($casos as $campo => [$cambios, $mensaje]) {
            $this->actingAs($this->consultor)->post('/participantes', $this->datos($cambios))
                ->assertSessionHasErrors([$campo => $mensaje]);
        }

        $this->assertSame(1, Participante::count());
    }

    // ===== Editar =====

    public function test_cambia_de_grupo_solo_dentro_de_su_empresa(): void
    {
        $otroProceso = Proceso::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        $otroGrupo = Grupo::create(['id_proceso' => $otroProceso->id_proceso, 'nombre' => 'Grupo B']);
        $ajeno = $this->grupoAjeno();
        $ana = $this->participante();
        $url = "/participantes/{$ana->id_participante}";

        // El admin ve todas las empresas, pero al editar solo le ofrece grupos de la del participante.
        $this->actingAs($this->admin)->get("{$url}/editar")
            ->assertSee('Pagos › Grupo B')
            ->assertDontSee('Grupo ajeno');

        $this->actingAs($this->admin)->put($url, $this->datos(['correo' => 'ana@empresa.com', 'id_grupo' => $ajeno->id_grupo]))
            ->assertSessionHasErrors(['id_grupo' => 'Elige un grupo activo de su misma empresa.']);

        $this->actingAs($this->admin)->put($url, $this->datos(['correo' => 'ana@empresa.com', 'id_grupo' => $otroGrupo->id_grupo]))
            ->assertSessionHasNoErrors();

        $this->assertSame($otroGrupo->id_grupo, $ana->fresh()->id_grupo);
    }

    public function test_puede_quedarse_en_su_grupo_aunque_este_de_baja(): void
    {
        $ana = $this->participante();
        $this->grupo->update(['estado' => false]);

        $this->actingAs($this->consultor)->put("/participantes/{$ana->id_participante}", $this->datos(['correo' => 'ana@empresa.com', 'rol' => 'Líder']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Líder', $ana->fresh()->rol);
    }

    // ===== Baja y reactivar =====

    public function test_reactivar_se_bloquea_si_su_grupo_esta_de_baja(): void
    {
        $ana = $this->participante(null, 'Ana', ['estado' => false]);
        $this->grupo->update(['estado' => false]);

        $this->actingAs($this->consultor)->patch("/participantes/{$ana->id_participante}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar a Ana Gómez: su grupo (Grupo 1) está dado de baja.');

        $this->assertFalse($ana->fresh()->estado);
    }

    public function test_aparece_en_el_menu(): void
    {
        $this->actingAs($this->consultor)->get('/')->assertSee('href="'.route('participantes.index').'"', false);
    }
}
