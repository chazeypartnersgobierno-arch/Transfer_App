<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Exports\HojaDePlantilla;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class PlantillaDeCargaTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
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

    private function bloque(string $nombre, ?Empresa $empresa = null, bool $estado = true): Bloque
    {
        return Bloque::create(['id_empresa' => ($empresa ?? $this->empresa)->id_empresa, 'nombre' => $nombre, 'estado' => $estado]);
    }

    /**
     * Abre el .xlsx descargado, como lo haría Excel. En una prueba la respuesta no se
     * envía, así que el archivo temporal no se borra solo: se borra aquí.
     */
    private function abrir(TestResponse $respuesta): Spreadsheet
    {
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $libro = IOFactory::load($ruta);
        @unlink($ruta);

        return $libro;
    }

    /** Valores de una columna de la hoja Listas, sin el encabezado ni las celdas vacías. */
    private function lista(Spreadsheet $libro, string $columna): array
    {
        $hoja = $libro->getSheetByName('Listas');
        $valores = [];

        for ($fila = 2; $fila <= $hoja->getHighestRow(); $fila++) {
            $valor = (string) $hoja->getCell("{$columna}{$fila}")->getValue();
            if ($valor !== '') {
                $valores[] = $valor;
            }
        }

        return $valores;
    }

    private function validacion(Worksheet $hoja, string $columna): ?DataValidation
    {
        return $hoja->getDataValidationCollection()["{$columna}2:{$columna}".HojaDePlantilla::ULTIMA_FILA] ?? null;
    }

    private function encabezados(Worksheet $hoja): array
    {
        return $hoja->rangeToArray('A1:'.$hoja->getHighestColumn().'1')[0];
    }

    public function test_sin_sesion_redirige_a_login(): void
    {
        $this->get('/carga')->assertRedirect(route('login'));
        $this->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa)->assertRedirect(route('login'));
    }

    public function test_el_consultor_no_entra(): void
    {
        foreach ([$this->usuario(RolApp::Consultor), $this->usuario(RolApp::Consultor, $this->empresa)] as $consultor) {
            $this->actingAs($consultor)->get('/carga')->assertForbidden();
            $this->actingAs($consultor)->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa)->assertForbidden();
            $this->actingAs($consultor)->get('/')->assertDontSee('Carga desde Excel');
        }
    }

    public function test_el_admin_de_chazey_elige_empresa_activa(): void
    {
        Empresa::create(['nombre' => 'Empresa Inactiva', 'sigla' => 'INA', 'estado' => false]);

        $this->actingAs($this->usuario())->get('/carga')
            ->assertOk()
            ->assertSee('Descargar plantilla')
            ->assertSee('name="id_empresa"', false)
            ->assertSee('Empresa Uno')
            ->assertDontSee('Empresa Inactiva')
            ->assertSee(route('carga.index'), false);
    }

    public function test_sin_empresas_activas_lo_indica(): void
    {
        $this->empresa->update(['estado' => false]);

        $this->actingAs($this->usuario())->get('/carga')
            ->assertOk()
            ->assertSee('Primero registra una empresa activa.')
            ->assertDontSee('Descargar plantilla');
    }

    public function test_valida_la_empresa_elegida(): void
    {
        $inactiva = Empresa::create(['nombre' => 'Inactiva', 'sigla' => 'INA', 'estado' => false]);
        $admin = $this->usuario();

        $this->actingAs($admin)->from('/carga')->get('/carga/plantilla')
            ->assertRedirect('/carga')
            ->assertSessionHasErrors(['id_empresa' => 'Elige una empresa.']);

        $this->actingAs($admin)->from('/carga')->get("/carga/plantilla?id_empresa={$inactiva->id_empresa}")
            ->assertSessionHasErrors(['id_empresa' => 'La empresa no existe o está dada de baja.']);

        $this->actingAs($admin)->from('/carga')->get('/carga/plantilla?id_empresa[]=1')
            ->assertSessionHasErrors('id_empresa');
    }

    public function test_la_plantilla_tiene_las_hojas_y_columnas_acordadas(): void
    {
        $respuesta = $this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa);
        $respuesta->assertDownload('plantilla_carga_uno.xlsx');

        $libro = $this->abrir($respuesta);

        $this->assertSame(['Instrucciones', 'Actividades', 'Participantes', 'Listas'], $libro->getSheetNames());
        $this->assertSame('Instrucciones', $libro->getActiveSheet()->getTitle());
        $this->assertSame(['Macroproceso', 'Proceso', 'Subproceso', 'Actividad', 'Bloque', 'Tipo'], $this->encabezados($libro->getSheetByName('Actividades')));
        $this->assertSame(['Nombre', 'Apellidos', 'Correo', 'Fecha de ingreso', 'Bloque', 'Grupo', 'Rol'], $this->encabezados($libro->getSheetByName('Participantes')));
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $libro->getSheetByName('Listas')->getSheetState());
        $this->assertNull($libro->getSheetByName('Capacitaciones'));

        // Las hojas de datos solo traen el encabezado: nada de ejemplos que se carguen por error.
        foreach (['Actividades', 'Participantes'] as $nombre) {
            $filasConDatos = collect($libro->getSheetByName($nombre)->toArray())
                ->skip(1)
                ->filter(fn (array $fila) => collect($fila)->filter(fn ($valor) => $valor !== null && $valor !== '')->isNotEmpty());

            $this->assertCount(0, $filasConDatos, "La hoja {$nombre} trae filas de datos");
        }
    }

    public function test_las_instrucciones_explican_transicion_y_las_columnas(): void
    {
        $libro = $this->abrir($this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa));
        $texto = collect($libro->getSheetByName('Instrucciones')->toArray())->flatten()->filter()->implode("\n");

        $this->assertStringContainsString('Empresa Uno (UNO)', $texto);
        $this->assertStringContainsString('Transición: la actividad pasa por las tres fases', $texto);
        $this->assertStringContainsString('Toda actividad lleva un bloque', $texto);
        $this->assertStringNotContainsString('Todos', $texto);
        $this->assertStringContainsString('una sola vez por bloque y tipo', $texto);

        foreach (['Macroproceso', 'Subproceso', 'Apellidos', 'Fecha de ingreso', 'Rol'] as $columna) {
            $this->assertStringContainsString($columna, $texto);
        }
    }

    public function test_las_listas_traen_solo_el_catalogo_activo_de_la_empresa(): void
    {
        $zeta = $this->bloque('Zeta');
        $alfa = $this->bloque('Alfa');
        $this->bloque('Bloque inactivo', null, false);
        $this->bloque('De otra empresa', Empresa::create(['nombre' => 'Otra', 'sigla' => 'OTR']));
        Grupo::create(['id_bloque' => $zeta->id_bloque, 'nombre' => 'Grupo 1']);
        Grupo::create(['id_bloque' => $alfa->id_bloque, 'nombre' => 'Grupo 1']);
        Grupo::create(['id_bloque' => $alfa->id_bloque, 'nombre' => 'Grupo 2']);
        Grupo::create(['id_bloque' => $alfa->id_bloque, 'nombre' => 'Grupo inactivo', 'estado' => false]);

        $libro = $this->abrir($this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa));

        $this->assertSame(['Alfa', 'Zeta'], $this->lista($libro, 'A'));
        $this->assertSame(['Grupo 1', 'Grupo 2'], $this->lista($libro, 'B'));
        $this->assertSame(['Onboarding', 'Transición'], $this->lista($libro, 'C'));
    }

    public function test_las_columnas_con_lista_desplegable_apuntan_a_la_hoja_listas(): void
    {
        $bloque = $this->bloque('Tesorería');
        Grupo::create(['id_bloque' => $bloque->id_bloque, 'nombre' => 'Grupo 1']);

        $libro = $this->abrir($this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa));
        $actividades = $libro->getSheetByName('Actividades');
        $participantes = $libro->getSheetByName('Participantes');

        $esperadas = [
            [$actividades, 'E', "'Listas'!\$A\$2:\$A\$2"],
            [$actividades, 'F', "'Listas'!\$C\$2:\$C\$3"],
            [$participantes, 'E', "'Listas'!\$A\$2:\$A\$2"],
            [$participantes, 'F', "'Listas'!\$B\$2:\$B\$2"],
        ];

        foreach ($esperadas as [$hoja, $columna, $formula]) {
            $validacion = $this->validacion($hoja, $columna);
            $this->assertNotNull($validacion, "Falta la lista en {$hoja->getTitle()}!{$columna}");
            $this->assertSame(DataValidation::TYPE_LIST, $validacion->getType());
            $this->assertSame($formula, $validacion->getFormula1());
            $this->assertTrue($validacion->getShowErrorMessage());
        }

        // Rol es texto libre: sin lista.
        $this->assertNull($this->validacion($participantes, 'G'));
    }

    public function test_sin_bloques_ni_grupos_la_plantilla_se_genera_igual(): void
    {
        $libro = $this->abrir($this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa));

        $this->assertSame([], $this->lista($libro, 'A'));
        $this->assertNull($this->validacion($libro->getSheetByName('Participantes'), 'E'));
        $this->assertNull($this->validacion($libro->getSheetByName('Actividades'), 'E'));
        $this->assertNotNull($this->validacion($libro->getSheetByName('Actividades'), 'F'));
    }

    public function test_un_nombre_que_parece_formula_se_guarda_como_texto(): void
    {
        $this->bloque('=1+1');

        $libro = $this->abrir($this->actingAs($this->usuario())->get('/carga/plantilla?id_empresa='.$this->empresa->id_empresa));

        $celda = $libro->getSheetByName('Listas')->getCell('A2');
        $this->assertSame('=1+1', $celda->getValue());
        $this->assertFalse($celda->isFormula());
    }

    public function test_el_menu_y_el_inicio_muestran_la_carga_al_admin(): void
    {
        $this->actingAs($this->usuario())->get('/')
            ->assertOk()
            ->assertSee(route('carga.index'), false)
            ->assertSee('Carga desde Excel');
    }
}
