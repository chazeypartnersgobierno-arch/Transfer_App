<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Empresa;
use App\Models\Fase;
use App\Models\Usuario;
use Database\Seeders\FaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaseTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private function usuario(RolApp $rol = RolApp::Admin, ?Empresa $empresa = null): Usuario
    {
        $this->contador++;

        return Usuario::create([
            'nombre' => 'Prueba',
            'correo' => "u{$this->contador}@prueba.test",
            'contrasena_hash' => 'clave-de-prueba',
            'rol_app' => $rol,
            'id_empresa' => $empresa?->id_empresa,
        ]);
    }

    private function fase(int $orden = 1): Fase
    {
        (new FaseSeeder)->run();

        return Fase::where('orden', $orden)->sole();
    }

    public function test_el_seeder_crea_las_fases_una_sola_vez(): void
    {
        (new FaseSeeder)->run();
        (new FaseSeeder)->run();

        $this->assertSame(
            [
                ['Onboarding', 1, true],
                ['Transferencia', 2, true],
                ['Estabilización', 3, true],
                ['Validación', 4, false],
            ],
            Fase::orderBy('orden')->get()->map(fn (Fase $f) => [$f->nombre, $f->orden, $f->tiene_sesiones])->all()
        );
    }

    public function test_el_seeder_no_duplica_ni_revierte_una_fase_renombrada(): void
    {
        $this->fase()->update(['nombre' => 'Inducción']);

        (new FaseSeeder)->run();

        $this->assertSame(4, Fase::count());
        $this->assertSame('Inducción', Fase::where('orden', 1)->value('nombre'));
    }

    public function test_sin_sesion_redirige_a_login(): void
    {
        $fase = $this->fase();

        $this->get('/fases')->assertRedirect(route('login'));
        $this->get("/fases/{$fase->id_fase}/editar")->assertRedirect(route('login'));
        $this->put("/fases/{$fase->id_fase}", ['nombre' => 'X'])->assertRedirect(route('login'));
    }

    public function test_el_admin_de_chazey_ve_en_orden_y_con_editar(): void
    {
        $this->fase();

        $this->actingAs($this->usuario())->get('/fases')
            ->assertOk()
            ->assertSeeInOrder(['Onboarding', 'Transferencia', 'Estabilización', 'Validación'])
            ->assertSee('Editar');
    }

    public function test_el_admin_de_chazey_cambia_el_nombre(): void
    {
        $fase = $this->fase();
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/fases/{$fase->id_fase}/editar")
            ->assertOk()
            ->assertSee('value="Onboarding"', false);

        $this->actingAs($admin)->put("/fases/{$fase->id_fase}", ['nombre' => 'Inducción'])
            ->assertRedirect(route('fases.index'))
            ->assertSessionHas('exito');

        $this->assertSame('Inducción', $fase->fresh()->nombre);
    }

    public function test_solo_cambia_el_nombre_aunque_envien_otros_datos(): void
    {
        $fase = $this->fase(4);

        $this->actingAs($this->usuario())->put("/fases/{$fase->id_fase}", [
            'nombre' => 'Cierre',
            'orden' => 1,
            'tiene_sesiones' => 1,
        ])->assertSessionHasNoErrors();

        $fase->refresh();
        $this->assertSame('Cierre', $fase->nombre);
        $this->assertSame(4, $fase->orden);
        $this->assertFalse($fase->tiene_sesiones);
    }

    public function test_validaciones_con_mensajes_en_espanol(): void
    {
        $fase = $this->fase();
        $admin = $this->usuario();

        $this->actingAs($admin)->put("/fases/{$fase->id_fase}", ['nombre' => ''])
            ->assertSessionHasErrors(['nombre' => 'Escribe el nombre de la fase.']);

        $this->actingAs($admin)->put("/fases/{$fase->id_fase}", ['nombre' => 'Transferencia'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe una fase con este nombre.']);

        $this->actingAs($admin)->put("/fases/{$fase->id_fase}", ['nombre' => str_repeat('A', 101)])
            ->assertSessionHasErrors(['nombre' => 'El nombre no puede tener más de 100 caracteres.']);

        $this->assertSame('Onboarding', $fase->fresh()->nombre);
    }

    public function test_puede_guardar_conservando_su_propio_nombre(): void
    {
        $fase = $this->fase();

        $this->actingAs($this->usuario())->put("/fases/{$fase->id_fase}", ['nombre' => 'Onboarding'])
            ->assertSessionHasNoErrors();
    }

    public function test_los_demas_usuarios_ven_la_lista_pero_no_cambian_nada(): void
    {
        $fase = $this->fase();
        $empresa = Empresa::create(['nombre' => 'Cliente', 'sigla' => 'CLI']);

        $usuarios = [
            $this->usuario(RolApp::Consultor),
            $this->usuario(RolApp::Admin, $empresa),
            $this->usuario(RolApp::Consultor, $empresa),
        ];

        foreach ($usuarios as $usuario) {
            $this->actingAs($usuario)->get('/fases')
                ->assertOk()
                ->assertSee('Onboarding')
                ->assertDontSee('Editar');

            $this->actingAs($usuario)->get("/fases/{$fase->id_fase}/editar")->assertForbidden();
            $this->actingAs($usuario)->put("/fases/{$fase->id_fase}", ['nombre' => 'X'])->assertForbidden();
        }

        $this->assertSame('Onboarding', $fase->fresh()->nombre);
    }

    public function test_no_se_pueden_crear_ni_borrar_fases(): void
    {
        $fase = $this->fase();
        $admin = $this->usuario();

        $this->actingAs($admin)->post('/fases', ['nombre' => 'X'])->assertMethodNotAllowed();
        $this->actingAs($admin)->delete("/fases/{$fase->id_fase}")->assertMethodNotAllowed();

        $this->assertSame(4, Fase::count());
    }
}
