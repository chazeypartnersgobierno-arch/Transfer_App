<?php

namespace Tests\Feature;

use App\Enums\EstadoActividad;
use App\Enums\TipoActividad;
use App\Models\Actividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Grupo;
use App\Models\Seguimiento;
use Database\Seeders\FaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class SeguimientoTest extends TestCase
{
    use RefreshDatabase;

    private Actividad $actividad;

    private Grupo $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        (new FaseSeeder)->run();

        $empresa = Empresa::create(['nombre' => 'Empresa', 'sigla' => 'EMP']);
        $bloque = Bloque::create(['id_empresa' => $empresa->id_empresa, 'nombre' => 'Bloque']);
        $this->grupo = Grupo::create(['id_bloque' => $bloque->id_bloque, 'nombre' => 'Grupo']);
        $this->actividad = Actividad::create([
            'id_empresa' => $empresa->id_empresa,
            'id_bloque' => $bloque->id_bloque,
            'tipo' => TipoActividad::Transicion,
            'nombre' => 'Actividad',
        ]);
    }

    private function seguimiento(int $orden = 2, array $extra = []): Seguimiento
    {
        return Seguimiento::create([
            'id_actividad' => $this->actividad->id_actividad,
            'id_grupo' => $this->grupo->id_grupo,
            'id_fase' => Fase::where('orden', $orden)->sole()->id_fase,
            ...$extra,
        ]);
    }

    public function test_fecha_cierre_se_llena_al_pasar_a_completada(): void
    {
        Date::setTestNow('2026-11-07 10:00:00');
        $seguimiento = $this->seguimiento();

        $this->assertNull($seguimiento->fresh()->fecha_cierre);

        $seguimiento->update(['estado_actividad' => EstadoActividad::Completada]);

        $this->assertSame('2026-11-07', $seguimiento->fresh()->fecha_cierre->toDateString());
    }

    public function test_al_reabrir_se_vacia_y_se_vuelve_a_llenar(): void
    {
        Date::setTestNow('2026-11-07 10:00:00');
        $seguimiento = $this->seguimiento();
        $seguimiento->update(['estado_actividad' => EstadoActividad::Completada]);

        $seguimiento->update(['estado_actividad' => EstadoActividad::EnCurso]);
        $this->assertNull($seguimiento->fresh()->fecha_cierre);

        Date::setTestNow('2026-11-20 10:00:00');
        $seguimiento->update(['estado_actividad' => EstadoActividad::Completada]);
        $this->assertSame('2026-11-20', $seguimiento->fresh()->fecha_cierre->toDateString());
    }

    public function test_cambiar_otros_datos_no_mueve_fecha_cierre(): void
    {
        Date::setTestNow('2026-11-07 10:00:00');
        $seguimiento = $this->seguimiento();
        $seguimiento->update(['estado_actividad' => EstadoActividad::Completada]);

        Date::setTestNow('2026-12-01 10:00:00');
        $seguimiento->update(['comentarios' => 'Sin cambiar el estado']);

        $this->assertSame('2026-11-07', $seguimiento->fresh()->fecha_cierre->toDateString());
    }

    public function test_pasar_de_pendiente_a_en_curso_no_llena_fecha_cierre(): void
    {
        $seguimiento = $this->seguimiento(2, ['estado_actividad' => EstadoActividad::Pendiente]);

        $seguimiento->update(['estado_actividad' => EstadoActividad::EnCurso]);

        $this->assertNull($seguimiento->fresh()->fecha_cierre);
    }

    public function test_crearlo_completado_tambien_llena_fecha_cierre(): void
    {
        Date::setTestNow('2026-11-07 10:00:00');

        $seguimiento = $this->seguimiento(2, ['estado_actividad' => EstadoActividad::Completada]);

        $this->assertSame('2026-11-07', $seguimiento->fresh()->fecha_cierre->toDateString());
    }

    public function test_el_estado_se_guarda_con_el_texto_de_la_lista(): void
    {
        $seguimiento = $this->seguimiento(2, ['estado_actividad' => EstadoActividad::EnCurso]);

        $this->assertDatabaseHas('seguimientos', ['id_seguimiento' => $seguimiento->id_seguimiento, 'estado_actividad' => 'En curso']);
    }

    public function test_una_actividad_puede_tener_un_seguimiento_por_fase_en_el_mismo_grupo(): void
    {
        $this->seguimiento(2);
        $this->seguimiento(3);
        $this->seguimiento(4);

        $this->assertSame(3, Seguimiento::count());
    }

    public function test_la_base_rechaza_repetir_actividad_grupo_y_fase(): void
    {
        $this->seguimiento(2);

        $this->expectException(QueryException::class);

        $this->seguimiento(2);
    }

    public function test_cada_seguimiento_conoce_su_fase(): void
    {
        $this->assertSame(3, $this->seguimiento(3)->fase->orden);
    }
}
