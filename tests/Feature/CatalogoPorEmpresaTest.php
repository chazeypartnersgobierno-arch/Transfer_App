<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Models\Aplicativo;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bloques y aplicativos siguen las mismas reglas, así que cada prueba corre para ambos.
 */
class CatalogoPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    public static function catalogos(): array
    {
        return [
            'bloques' => ['bloques', Bloque::class],
            'aplicativos' => ['aplicativos', Aplicativo::class],
        ];
    }

    private function singular(string $ruta): string
    {
        return rtrim($ruta, 's');
    }

    private function textoNuevo(string $ruta): string
    {
        return 'Nuevo '.$this->singular($ruta);
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

    private function empresa(string $sigla = 'PROPIA', bool $estado = true): Empresa
    {
        return Empresa::create(['nombre' => "Empresa {$sigla}", 'sigla' => $sigla, 'estado' => $estado]);
    }

    /** @param class-string $modelo */
    private function registro(string $modelo, Empresa $empresa, string $nombre = 'Registro uno', bool $estado = true)
    {
        return $modelo::create(['id_empresa' => $empresa->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    #[DataProvider('catalogos')]
    public function test_sin_sesion_redirige_a_login(string $ruta, string $modelo): void
    {
        $registro = $this->registro($modelo, $this->empresa());

        $this->get("/{$ruta}")->assertRedirect(route('login'));
        $this->get("/{$ruta}/{$registro->getKey()}/editar")->assertRedirect(route('login'));
    }

    #[DataProvider('catalogos')]
    public function test_admin_de_chazey_ve_todos_con_su_empresa_y_filtra(string $ruta, string $modelo): void
    {
        $a = $this->empresa('AAA');
        $b = $this->empresa('BBB');
        $this->registro($modelo, $a, 'Registro de A');
        $this->registro($modelo, $b, 'Registro de B');
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/{$ruta}")
            ->assertOk()
            ->assertSee('Registro de A')
            ->assertSee('Registro de B')
            ->assertSee('Empresa AAA')
            ->assertSee($this->textoNuevo($ruta))
            ->assertSee('Todas las empresas');

        $this->actingAs($admin)->get("/{$ruta}?id_empresa={$a->id_empresa}")
            ->assertSee('Registro de A')
            ->assertDontSee('Registro de B');
    }

    #[DataProvider('catalogos')]
    public function test_admin_de_chazey_crea_eligiendo_empresa(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa();
        $inactiva = $this->empresa('INACTIVA', false);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/{$ruta}/crear")
            ->assertOk()
            ->assertSee($this->textoNuevo($ruta))
            ->assertSee('Empresa PROPIA')
            ->assertDontSee('Empresa INACTIVA');

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => $empresa->id_empresa, 'nombre' => 'Creado'])
            ->assertRedirect(route("{$ruta}.index"))
            ->assertSessionHas('exito');

        $this->assertSame($empresa->id_empresa, $modelo::where('nombre', 'Creado')->sole()->id_empresa);

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => $inactiva->id_empresa, 'nombre' => 'Otro'])
            ->assertSessionHasErrors(['id_empresa' => 'La empresa no existe o está dada de baja.']);
    }

    #[DataProvider('catalogos')]
    public function test_sin_empresas_activas_no_abre_el_formulario(string $ruta, string $modelo): void
    {
        $this->empresa('INACTIVA', false);

        $this->actingAs($this->usuario())->get("/{$ruta}/crear")
            ->assertRedirect(route("{$ruta}.index"))
            ->assertSessionHas('error', 'Primero registra una empresa activa.');
    }

    #[DataProvider('catalogos')]
    public function test_validaciones_con_mensajes_en_espanol(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa();
        $this->registro($modelo, $empresa, 'Repetido');
        $admin = $this->usuario();

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => '', 'nombre' => ''])
            ->assertSessionHasErrors(['id_empresa' => 'Elige una empresa.', 'nombre' => "Escribe el nombre del {$this->singular($ruta)}."]);

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => $empresa->id_empresa, 'nombre' => 'Repetido'])
            ->assertSessionHasErrors(['nombre' => "Esta empresa ya tiene un {$this->singular($ruta)} con este nombre."]);

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => $empresa->id_empresa, 'nombre' => str_repeat('A', 256)])
            ->assertSessionHasErrors('nombre');

        $this->actingAs($admin)->post("/{$ruta}", ['id_empresa' => ['x'], 'nombre' => 'Otro'])
            ->assertSessionHasErrors('id_empresa');

        $this->assertSame(1, $modelo::count());
    }

    #[DataProvider('catalogos')]
    public function test_otra_empresa_puede_usar_el_mismo_nombre(string $ruta, string $modelo): void
    {
        $this->registro($modelo, $this->empresa('AAA'), 'Compartido');
        $otra = $this->empresa('BBB');

        $this->actingAs($this->usuario())->post("/{$ruta}", ['id_empresa' => $otra->id_empresa, 'nombre' => 'Compartido'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $modelo::where('nombre', 'Compartido')->count());
    }

    #[DataProvider('catalogos')]
    public function test_consultor_ve_pero_no_cambia_nada(string $ruta, string $modelo): void
    {
        $propia = $this->empresa('PROPIA');
        $ajena = $this->empresa('AJENA');
        $registro = $this->registro($modelo, $propia, 'Registro propio');
        $this->registro($modelo, $ajena, 'Registro ajeno');

        $consultor = $this->usuario(RolApp::Consultor, $propia);
        $sinAsignacion = $this->usuario(RolApp::Consultor);

        // Solo ve lo de la empresa de su asignación activa.
        $this->actingAs($consultor)->get("/{$ruta}")
            ->assertOk()
            ->assertSee('Registro propio')
            ->assertDontSee('Registro ajeno')
            ->assertDontSee('Todas las empresas')
            ->assertDontSee($this->textoNuevo($ruta))
            ->assertDontSee('Dar de baja');

        // Pedir la empresa ajena en el filtro no muestra nada de ella.
        $this->actingAs($consultor)->get("/{$ruta}?id_empresa={$ajena->id_empresa}")->assertDontSee('Registro ajeno');

        // Sin asignación activa no ve nada.
        $this->actingAs($sinAsignacion)->get("/{$ruta}")
            ->assertOk()
            ->assertDontSee('Registro propio')
            ->assertDontSee('Registro ajeno');

        $url = "/{$ruta}/{$registro->getKey()}";

        $this->actingAs($consultor)->get("/{$ruta}/crear")->assertForbidden();
        $this->actingAs($consultor)->post("/{$ruta}", ['id_empresa' => $propia->id_empresa, 'nombre' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->get("{$url}/editar")->assertForbidden();
        $this->actingAs($consultor)->put($url, ['nombre' => 'X'])->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/baja")->assertForbidden();
        $this->actingAs($consultor)->patch("{$url}/reactivar")->assertForbidden();

        $this->assertSame('Registro propio', $registro->fresh()->nombre);
        $this->assertTrue($registro->fresh()->estado);
        $this->assertSame(2, $modelo::count());
    }

    #[DataProvider('catalogos')]
    public function test_al_editar_la_empresa_no_cambia(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa('AAA');
        $otra = $this->empresa('BBB');
        $registro = $this->registro($modelo, $empresa);
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/{$ruta}/{$registro->getKey()}/editar")
            ->assertOk()
            ->assertSee('Empresa AAA')
            ->assertDontSee('name="id_empresa"', false);

        $this->actingAs($admin)->put("/{$ruta}/{$registro->getKey()}", ['nombre' => 'Renombrado', 'id_empresa' => $otra->id_empresa])
            ->assertSessionHasNoErrors();

        $registro->refresh();
        $this->assertSame('Renombrado', $registro->nombre);
        $this->assertSame($empresa->id_empresa, $registro->id_empresa);
    }

    #[DataProvider('catalogos')]
    public function test_al_editar_valida_el_nombre_dentro_de_su_empresa(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa();
        $this->registro($modelo, $empresa, 'Ocupado');
        $registro = $this->registro($modelo, $empresa, 'Actual');
        $admin = $this->usuario();
        $url = "/{$ruta}/{$registro->getKey()}";

        $this->actingAs($admin)->put($url, ['nombre' => 'Ocupado'])
            ->assertSessionHasErrors(['nombre' => "Esta empresa ya tiene un {$this->singular($ruta)} con este nombre."]);

        $this->actingAs($admin)->put($url, ['nombre' => 'Actual'])->assertSessionHasNoErrors();
    }

    #[DataProvider('catalogos')]
    public function test_dar_de_baja_no_borra_y_reactivar_la_recupera(string $ruta, string $modelo): void
    {
        $registro = $this->registro($modelo, $this->empresa(), 'Registro uno');
        $admin = $this->usuario();
        $url = "/{$ruta}/{$registro->getKey()}";

        $this->actingAs($admin)->from("/{$ruta}")->patch("{$url}/baja")
            ->assertRedirect("/{$ruta}")
            ->assertSessionHas('exito');
        $this->assertFalse($registro->fresh()->estado);
        $this->assertSame(1, $modelo::count());

        // Se revisa la celda: el mensaje de éxito también menciona el nombre.
        $this->actingAs($admin)->get("/{$ruta}")->assertDontSee('celda-titulo">Registro uno', false);
        $this->actingAs($admin)->get("/{$ruta}?ver_bajas=1")->assertSee('Registro uno')->assertSee('Reactivar');

        $this->actingAs($admin)->from("/{$ruta}")->patch("{$url}/reactivar")->assertSessionHas('exito');
        $this->assertTrue($registro->fresh()->estado);
    }

    #[DataProvider('catalogos')]
    public function test_no_se_reactiva_si_su_empresa_esta_de_baja(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa('INACTIVA', false);
        $registro = $this->registro($modelo, $empresa, 'Registro uno', false);

        $this->actingAs($this->usuario())->from("/{$ruta}")->patch("/{$ruta}/{$registro->getKey()}/reactivar")
            ->assertSessionHas('error', 'No se puede reactivar Registro uno: su empresa está dada de baja.');

        $this->assertFalse($registro->fresh()->estado);
    }

    #[DataProvider('catalogos')]
    public function test_busca_con_comodines_como_texto_y_pagina(string $ruta, string $modelo): void
    {
        $empresa = $this->empresa();

        for ($i = 1; $i <= 16; $i++) {
            $this->registro($modelo, $empresa, sprintf('Demo %02d', $i));
        }

        $this->registro($modelo, $empresa, 'Otro');
        $admin = $this->usuario();

        $this->actingAs($admin)->get("/{$ruta}?buscar=Demo")
            ->assertSee('Demo 15')
            ->assertDontSee('Demo 16')
            ->assertDontSee('>Otro<', false)
            ->assertSee('buscar=Demo&amp;page=2', false);

        $this->actingAs($admin)->get("/{$ruta}?buscar=%25")->assertDontSee('Demo 01');
        $this->actingAs($admin)->get("/{$ruta}?buscar[]=x")->assertOk();
    }

    public function test_el_menu_y_el_inicio_muestran_los_catalogos(): void
    {
        $this->actingAs($this->usuario(RolApp::Consultor))->get('/')
            ->assertOk()
            ->assertSee(route('bloques.index'), false)
            ->assertSee(route('aplicativos.index'), false);
    }
}
