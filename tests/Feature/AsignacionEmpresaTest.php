<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\AsignacionEmpresa;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use App\Models\Usuario;
use App\Services\AsignadorDeEmpresas;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Asignaciones de consultores a empresas (tabla usuario_empresa) y lo que cada uno ve.
 */
class AsignacionEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private AsignadorDeEmpresas $asignador;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();
        $this->asignador = new AsignadorDeEmpresas;
    }

    private function empresa(string $sigla, bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    private function usuario(RolApp $rol = RolApp::Consultor, string $nombre = 'Prueba'): Usuario
    {
        $this->contador++;

        return Usuario::create([
            'nombre' => $nombre,
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
        ]);
    }

    /** Un seguimiento de una actividad del bloque "Bloque <sigla>" de la empresa. */
    private function seguimiento(Empresa $empresa, string $actividad): Seguimiento
    {
        $bloque = Bloque::firstOrCreate(['id_empresa' => $empresa->id_empresa, 'nombre' => "Bloque {$empresa->sigla}"]);
        $grupo = Grupo::firstOrCreate(['id_bloque' => $bloque->id_bloque, 'nombre' => 'Grupo 1']);
        $actividad = Actividad::create([
            'id_empresa' => $empresa->id_empresa,
            'id_bloque' => $bloque->id_bloque,
            'tipo' => TipoActividad::Onboarding,
            'nombre' => $actividad,
        ]);

        return Seguimiento::create([
            'id_actividad' => $actividad->id_actividad,
            'id_grupo' => $grupo->id_grupo,
            'id_fase' => Fase::where('orden', 1)->sole()->id_fase,
        ]);
    }

    /** Ejecuta la asignación y devuelve el mensaje de error, o null si se pudo. */
    private function errorAlAsignar(Usuario $usuario, Empresa $empresa, ?string $fecha = null): ?string
    {
        try {
            $this->asignador->asignar($usuario, $empresa, $fecha ? today()->parse($fecha) : today());

            return null;
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }
    }

    // ===== Reglas de la asignación =====

    public function test_asignar_crea_una_asignacion_activa_desde_la_fecha_dada(): void
    {
        $empresa = $this->empresa('EPM');
        $consultor = $this->usuario();

        $asignacion = $this->asignador->asignar($consultor, $empresa, today()->subDays(10));

        $this->assertTrue($asignacion->activa);
        $this->assertSame(today()->subDays(10)->toDateString(), $asignacion->fecha_inicio->toDateString());
        $this->assertNull($asignacion->fecha_fin);
        $this->assertSame([$empresa->id_empresa], $consultor->idsEmpresasVisibles());
    }

    public function test_asignar_otra_empresa_cierra_la_anterior(): void
    {
        $primera = $this->empresa('UNO');
        $segunda = $this->empresa('DOS');
        $consultor = $this->usuario();

        $anterior = $this->asignador->asignar($consultor, $primera, today()->subMonth());
        $this->asignador->asignar($consultor, $segunda, today());

        $anterior->refresh();
        $this->assertFalse($anterior->activa);
        $this->assertSame(today()->toDateString(), $anterior->fecha_fin->toDateString());

        // Solo una activa, y el historial se conserva.
        $this->assertSame(1, $consultor->asignaciones()->activas()->count());
        $this->assertSame(2, $consultor->asignaciones()->count());
        $this->assertSame([$segunda->id_empresa], $consultor->idsEmpresasVisibles());
    }

    public function test_rechaza_lo_que_no_se_puede_asignar(): void
    {
        $empresa = $this->empresa('EPM');
        $consultor = $this->usuario();
        $this->asignador->asignar($consultor, $empresa, today());

        $this->assertSame('Prueba ya está asignado a Empresa EPM.', $this->errorAlAsignar($consultor, $empresa));
        $this->assertSame('La empresa Empresa BAJ está dada de baja.', $this->errorAlAsignar($consultor, $this->empresa('BAJ', false)));
        $this->assertSame('La fecha de inicio no puede ser futura.', $this->errorAlAsignar($consultor, $this->empresa('FUT'), today()->addDay()->toDateString()));
        $this->assertSame(
            'Solo los consultores se asignan a empresas: el administrador ve todas.',
            $this->errorAlAsignar($this->usuario(RolApp::Admin), $empresa)
        );

        // Ninguno de los intentos fallidos cerró la asignación vigente.
        $this->assertSame([$empresa->id_empresa], $consultor->idsEmpresasVisibles());
        $this->assertSame(1, AsignacionEmpresa::count());
    }

    public function test_cerrar_deja_al_consultor_sin_empresa(): void
    {
        $consultor = $this->usuario();
        $asignacion = $this->asignador->asignar($consultor, $this->empresa('EPM'), today());

        $this->asignador->cerrar($asignacion);

        $this->assertFalse($asignacion->fresh()->activa);
        $this->assertSame(today()->toDateString(), $asignacion->fresh()->fecha_fin->toDateString());
        $this->assertSame([], $consultor->idsEmpresasVisibles());
    }

    // ===== Lo que ve cada usuario =====

    public function test_el_consultor_ve_solo_la_empresa_de_su_asignacion_activa(): void
    {
        $uno = $this->empresa('UNO');
        $dos = $this->empresa('DOS');
        $this->seguimiento($uno, 'Conciliar UNO');
        $this->seguimiento($dos, 'Conciliar DOS');
        $consultor = $this->usuario();

        // Sin asignación no ve datos y el inicio se lo avisa.
        $this->actingAs($consultor)->get('/seguimientos')->assertOk()->assertDontSee('Conciliar UNO')->assertDontSee('Conciliar DOS');
        $this->actingAs($consultor)->get('/')->assertSee('No tienes una empresa asignada');

        $this->asignador->asignar($consultor, $uno, today());

        $this->actingAs($consultor)->get('/seguimientos')->assertSee('Conciliar UNO')->assertDontSee('Conciliar DOS');
        $this->actingAs($consultor)->get('/grupos')->assertSee('Bloque UNO')->assertDontSee('Bloque DOS');
        $this->actingAs($consultor)->get('/')->assertSee('Trabajas con Empresa UNO.')->assertDontSee('No tienes una empresa asignada');
    }

    public function test_al_cambiar_de_empresa_los_registros_se_quedan_en_la_empresa_original(): void
    {
        $uno = $this->empresa('UNO');
        $dos = $this->empresa('DOS');
        $seguimiento = $this->seguimiento($uno, 'Conciliar UNO');
        $consultor = $this->usuario();
        $this->asignador->asignar($consultor, $uno, today());

        $this->actingAs($consultor)->put("/seguimientos/{$seguimiento->id_seguimiento}", [
            'fecha_inicio' => '2027-01-10',
            'fecha_fin' => '2027-02-10',
            'estado_actividad' => 'En curso',
            'experto' => 'Ana',
        ])->assertSessionHas('exito');

        // Lo ve mientras está asignado a UNO (esta visita también consume el mensaje de éxito).
        $this->actingAs($consultor)->get('/seguimientos')->assertSee('Conciliar UNO');

        $this->asignador->asignar($consultor, $dos, today());

        // Ya no lo ve ni lo edita: es de la empresa UNO, no del consultor.
        $this->actingAs($consultor)->get('/seguimientos')->assertDontSee('Conciliar UNO');
        $this->actingAs($consultor)->get("/seguimientos/{$seguimiento->id_seguimiento}/editar")->assertNotFound();

        // El dato sigue intacto en su empresa: otro consultor de UNO y el admin lo ven.
        $otro = $this->usuario();
        $this->asignador->asignar($otro, $uno, today());
        $this->actingAs($otro)->get('/seguimientos')->assertSee('Conciliar UNO');
        $this->actingAs($this->usuario(RolApp::Admin))->get('/seguimientos')->assertSee('Conciliar UNO');
        $this->assertSame('Ana', $seguimiento->fresh()->experto);
    }

    public function test_el_admin_ve_todas_las_empresas_sin_asignaciones(): void
    {
        $this->seguimiento($this->empresa('UNO'), 'Conciliar UNO');
        $this->seguimiento($this->empresa('DOS'), 'Conciliar DOS');

        $this->actingAs($this->usuario(RolApp::Admin))->get('/seguimientos')
            ->assertSee('Conciliar UNO')
            ->assertSee('Conciliar DOS');
    }

    // ===== Baja de la empresa =====

    public function test_la_baja_de_la_empresa_solo_la_bloquean_asignaciones_activas(): void
    {
        $empresa = $this->empresa('EPM');
        $consultor = $this->usuario();
        $asignacion = $this->asignador->asignar($consultor, $empresa, today());
        $admin = $this->usuario(RolApp::Admin);

        $this->actingAs($admin)->patch("/empresas/{$empresa->id_empresa}/baja")
            ->assertSessionHas('error', 'No se puede dar de baja Empresa EPM. Todavía tiene activos: usuarios.');

        $this->asignador->cerrar($asignacion);

        $this->actingAs($admin)->patch("/empresas/{$empresa->id_empresa}/baja")->assertSessionHas('exito');
        $this->assertFalse($empresa->fresh()->estado);
    }
}
