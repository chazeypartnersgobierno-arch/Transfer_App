<?php

namespace Tests\Feature;

use App\Enums\RolApp;
use App\Enums\TipoActividad;
use App\Exports\PlantillaDeCarga;
use App\Models\Actividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Models\Seguimiento;
use App\Models\Usuario;
use App\Services\ArchivosDeCarga;
use App\Services\GeneradorDeSeguimientos;
use Database\Seeders\FaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class CargaDesdeExcelTest extends TestCase
{
    use RefreshDatabase;

    private int $contador = 0;

    private Empresa $empresa;

    private Bloque $tesoreria;

    private Grupo $grupo1;

    private Usuario $admin;

    /** Archivos .xlsx creados por la prueba, para borrarlos al final. */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Los archivos subidos van a un disco falso: no tocan storage real.
        Storage::fake('local');
        (new FaseSeeder)->run();

        $this->empresa = Empresa::create(['nombre' => 'Empresa Uno', 'sigla' => 'UNO']);
        $this->tesoreria = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Tesorería']);
        $this->grupo1 = Grupo::create(['id_bloque' => $this->tesoreria->id_bloque, 'nombre' => 'Grupo 1']);
        $this->admin = $this->usuario();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

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

    /**
     * Arma un .xlsx como el que llenaría el usuario. null en una hoja = la hoja no existe.
     */
    private function archivo(?array $actividades = [], ?array $participantes = [], ?array $encabezadosActividades = null): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $hojas = [
            'Actividades' => [$encabezadosActividades ?? PlantillaDeCarga::ENCABEZADOS_ACTIVIDADES, $actividades],
            'Participantes' => [PlantillaDeCarga::ENCABEZADOS_PARTICIPANTES, $participantes],
        ];

        foreach ($hojas as $titulo => [$encabezados, $filas]) {
            if ($filas === null) {
                continue;
            }

            $hoja = $libro->createSheet();
            $hoja->setTitle($titulo);
            $hoja->fromArray([$encabezados, ...$filas], null, 'A1', true);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'carga').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, 'carga.xlsx', null, null, true);
    }

    private function subir(UploadedFile $archivo, ?Usuario $usuario = null, ?Empresa $empresa = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin)->post('/carga', [
            'id_empresa' => ($empresa ?? $this->empresa)->id_empresa,
            'archivo' => $archivo,
        ]);
    }

    /** Sube el archivo y devuelve la vista previa. */
    private function revisar(UploadedFile $archivo): TestResponse
    {
        $this->subir($archivo)->assertRedirect(route('carga.vista-previa'));

        return $this->actingAs($this->admin)->get('/carga/vista-previa')->assertOk();
    }

    private function confirmar(): TestResponse
    {
        return $this->actingAs($this->admin)->post('/carga/confirmar');
    }

    private function archivosPendientes(): array
    {
        return Storage::disk('local')->files(ArchivosDeCarga::CARPETA);
    }

    // ===== Permisos =====

    public function test_el_consultor_no_puede_cargar(): void
    {
        $consultor = $this->usuario(RolApp::Consultor);

        $this->subir($this->archivo(), $consultor)->assertForbidden();
        $this->actingAs($consultor)->get('/carga/vista-previa')->assertForbidden();
        $this->actingAs($consultor)->post('/carga/confirmar')->assertForbidden();
        $this->actingAs($consultor)->post('/carga/cancelar')->assertForbidden();
        $this->assertSame([], $this->archivosPendientes());
    }

    public function test_el_admin_de_una_empresa_carga_solo_en_la_suya(): void
    {
        $ajena = Empresa::create(['nombre' => 'Ajena', 'sigla' => 'AJE']);
        Bloque::create(['id_empresa' => $ajena->id_empresa, 'nombre' => 'Solo ajeno']);
        $admin = $this->usuario(RolApp::Admin, $this->empresa);

        // Pide la empresa ajena, pero se revisa contra la suya: "Solo ajeno" no existe ahí.
        $this->subir($this->archivo([['', '', '', 'Tarea', 'Solo ajeno', 'Onboarding']]), $admin, $ajena);

        $this->actingAs($admin)->get('/carga/vista-previa')
            ->assertSee('Empresa Uno')
            ->assertSee('El bloque «Solo ajeno» no existe en esta empresa.');
    }

    public function test_la_pantalla_muestra_el_formulario_para_subir(): void
    {
        $this->actingAs($this->admin)->get('/carga')
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="archivo"', false)
            ->assertSee('id="empresa_plantilla"', false)
            ->assertSee('id="empresa_carga"', false);
    }

    // ===== Archivo =====

    public function test_valida_el_archivo(): void
    {
        $this->actingAs($this->admin)->post('/carga', ['id_empresa' => $this->empresa->id_empresa])
            ->assertSessionHasErrors(['archivo' => 'Elige el archivo.']);

        $this->actingAs($this->admin)->post('/carga', [
            'id_empresa' => $this->empresa->id_empresa,
            'archivo' => UploadedFile::fake()->createWithContent('carga.csv', "a,b\n1,2"),
        ])->assertSessionHasErrors(['archivo' => 'El archivo debe ser de Excel (.xlsx).']);

        $this->actingAs($this->admin)->post('/carga', ['archivo' => $this->archivo()])
            ->assertSessionHasErrors(['id_empresa' => 'Elige una empresa.']);

        $this->assertSame([], $this->archivosPendientes());
    }

    public function test_avisa_si_falta_una_hoja_o_una_columna(): void
    {
        $this->revisar($this->archivo(null, []))
            ->assertSee('Falta la hoja Actividades.')
            ->assertDontSee('Confirmar y guardar');

        $sinTipo = ['Macroproceso', 'Proceso', 'Subproceso', 'Actividad', 'Bloque'];
        $this->revisar($this->archivo([], [], $sinTipo))
            ->assertSee('En la hoja Actividades faltan las columnas: Tipo.');
    }

    public function test_un_archivo_sin_datos_no_se_puede_confirmar(): void
    {
        $this->revisar($this->archivo())
            ->assertSee('El archivo no tiene datos')
            ->assertDontSee('Confirmar y guardar');
    }

    public function test_las_columnas_se_buscan_por_nombre_y_las_de_mas_se_ignoran(): void
    {
        // Otro orden, encabezados en mayúsculas y columnas ID y Fecha que no se usan.
        $encabezados = ['ID', 'TIPO', 'actividad', 'Bloque', 'Fecha inicio', 'Macroproceso', 'Proceso', 'Subproceso'];

        $this->revisar($this->archivo([[99, 'Onboarding', 'Inducción', 'Tesorería', '01/01/2027', '', '', '']], [], $encabezados))
            ->assertSee('Confirmar y guardar');

        $this->confirmar()->assertSessionHas('exito');
        $this->assertSame('Inducción', Actividad::sole()->nombre);
    }

    // ===== Actividades =====

    public function test_carga_actividades_en_orden_y_genera_sus_seguimientos(): void
    {
        Grupo::create(['id_bloque' => $this->tesoreria->id_bloque, 'nombre' => 'Grupo 2']);
        Grupo::create(['id_bloque' => $this->tesoreria->id_bloque, 'nombre' => 'De baja', 'estado' => false]);
        $otro = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        Grupo::create(['id_bloque' => $otro->id_bloque, 'nombre' => 'Grupo P']);

        $archivo = $this->archivo([
            ['Finanzas', 'Tesorería', 'Bancos', 'Zeta conciliar', 'Tesorería', 'Transición'],
            ['', '', '', 'Alfa inducción', 'Tesorería', 'Onboarding'],
            [],
            ['', '', '', 'Onboarding de pagos', 'Pagos', 'Onboarding'],
        ]);

        // 2 grupos activos de Tesorería × (3 + 1) + 1 grupo de Pagos × 1 = 9.
        $this->revisar($archivo)
            ->assertSee('Se generarán 9 seguimientos')
            ->assertSee('Al confirmar se guardarán 3 actividades')
            ->assertSee('Confirmar y guardar')
            ->assertSee('Fila 5');

        $this->assertSame(0, Actividad::count());

        $this->confirmar()
            ->assertRedirect(route('carga.index'))
            ->assertSessionHas('exito', 'Se cargaron en Empresa Uno: 3 actividades (9 seguimientos).');

        $this->assertSame(['Zeta conciliar', 'Alfa inducción', 'Onboarding de pagos'], Actividad::orderBy('id_actividad')->pluck('nombre')->all());

        $primera = Actividad::where('nombre', 'Zeta conciliar')->sole();
        $this->assertSame(TipoActividad::Transicion, $primera->tipo);
        $this->assertSame($this->tesoreria->id_bloque, $primera->id_bloque);
        $this->assertSame('Bancos', $primera->subproceso);
        $this->assertTrue($primera->estado);
        $this->assertNull(Actividad::where('nombre', 'Alfa inducción')->sole()->macroproceso);
        $this->assertSame(9, Seguimiento::count());

        // El archivo temporal se borró.
        $this->assertSame([], $this->archivosPendientes());
    }

    public function test_el_bloque_es_obligatorio_y_debe_existir_activo(): void
    {
        Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Viejo', 'estado' => false]);

        $this->revisar($this->archivo([
            ['', '', '', 'Sin bloque', '', 'Onboarding'],
            ['', '', '', 'Para todos', 'Todos', 'Onboarding'],
            ['', '', '', 'Inexistente', 'Nómina', 'Onboarding'],
            ['', '', '', 'De baja', 'Viejo', 'Transición'],
        ]))
            ->assertSee('Falta el bloque.')
            ->assertSee('Ya no se usa «Todos»: cada actividad lleva un bloque de la empresa.')
            ->assertSee('El bloque «Nómina» no existe en esta empresa.')
            ->assertSee('El bloque Viejo está dado de baja.')
            ->assertSee('No se puede guardar')
            ->assertDontSee('Confirmar y guardar');

        // Aunque se envíe a mano, no se guarda nada.
        $this->confirmar()->assertRedirect(route('carga.vista-previa'));
        $this->assertSame(0, Actividad::count());
    }

    public function test_valida_actividad_tipo_y_largos(): void
    {
        $this->revisar($this->archivo([
            ['', '', '', '', 'Tesorería', 'Onboarding'],
            ['', '', '', 'Algo', 'Tesorería', ''],
            ['', '', '', 'Algo más', 'Tesorería', 'Capacitación'],
            [str_repeat('m', 256), '', '', str_repeat('a', 501), 'Tesorería', 'Onboarding'],
        ]))
            ->assertSee('Falta la actividad.')
            ->assertSee('Falta el tipo.')
            ->assertSee('El tipo «Capacitación» no es válido: usa Onboarding o Transición.')
            ->assertSee('El macroproceso no puede tener más de 255 caracteres.')
            ->assertSee('La actividad no puede tener más de 500 caracteres.');
    }

    public function test_compara_sin_mayusculas_acentos_ni_espacios_de_mas(): void
    {
        $this->revisar($this->archivo([
            ['  Finanzas ', 'Pagos   a  proveedores', '', 'Conciliar   cuentas ', '  TESORERIA ', 'transicion'],
        ]))->assertSee('Confirmar y guardar');

        $this->confirmar()->assertSessionHas('exito');

        $actividad = Actividad::sole();
        $this->assertSame($this->tesoreria->id_bloque, $actividad->id_bloque);
        $this->assertSame(TipoActividad::Transicion, $actividad->tipo);
        // Se guarda limpio, con sus mayúsculas.
        $this->assertSame('Conciliar cuentas', $actividad->nombre);
        $this->assertSame('Pagos a proveedores', $actividad->proceso);
        $this->assertSame('Finanzas', $actividad->macroproceso);
    }

    public function test_las_filas_repetidas_son_error(): void
    {
        $this->revisar($this->archivo([
            ['Finanzas', '', '', 'Conciliar', 'Tesorería', 'Onboarding'],
            ['Otra', '', '', 'Conciliar', 'Tesorería', 'Onboarding'],
            ['finanzas ', '', '', 'CONCILIAR', 'tesoreria', 'Onboarding'],
        ]))
            ->assertSee('Repite la fila 2.')
            ->assertDontSee('Repite la fila 3.')
            ->assertDontSee('Confirmar y guardar');
    }

    public function test_se_carga_una_sola_vez_por_bloque_y_tipo(): void
    {
        Actividad::create([
            'id_empresa' => $this->empresa->id_empresa,
            'id_bloque' => $this->tesoreria->id_bloque,
            'tipo' => TipoActividad::Onboarding,
            'nombre' => 'Creada a mano y dada de baja',
            'estado' => false,
        ]);
        $pagos = Bloque::create(['id_empresa' => $this->empresa->id_empresa, 'nombre' => 'Pagos']);
        Actividad::create(['id_empresa' => $this->empresa->id_empresa, 'id_bloque' => $pagos->id_bloque, 'tipo' => TipoActividad::Onboarding, 'nombre' => 'Otro bloque']);

        $this->revisar($this->archivo([
            ['', '', '', 'Nueva de onboarding', 'Tesorería', 'Onboarding'],
        ]))
            ->assertSee('El bloque Tesorería ya tiene 1 actividad de tipo Onboarding (se cuentan también las dadas de baja y las creadas a mano).')
            ->assertSee('El bloque Tesorería ya tiene actividades de tipo Onboarding.')
            ->assertDontSee('Confirmar y guardar');

        // El mismo bloque con el otro tipo sí se puede cargar.
        $this->revisar($this->archivo([
            ['', '', '', 'Nueva de transición', 'Tesorería', 'Transición'],
        ]))->assertSee('Confirmar y guardar');
    }

    public function test_si_falla_la_generacion_no_se_guarda_nada(): void
    {
        $this->app->instance(GeneradorDeSeguimientos::class, new class extends GeneradorDeSeguimientos
        {
            public function generarParaActividad(Actividad $actividad): int
            {
                throw new RuntimeException('Fallo simulado');
            }
        });

        $this->revisar($this->archivo(
            [['', '', '', 'Tarea', 'Tesorería', 'Onboarding']],
            [['Ana', 'Gómez', 'ana@empresa.com', '', 'Tesorería', 'Grupo 1', 'Analista']],
        ));

        $this->confirmar()->assertServerError();

        $this->assertSame(0, Actividad::count());
        $this->assertSame(0, Participante::count());
    }

    // ===== Participantes =====

    public function test_carga_participantes(): void
    {
        $serial = Date::PHPToExcel(new \DateTime('2027-01-15'));

        $this->revisar($this->archivo([], [
            ['Ana María', 'Gómez Ruiz', 'Ana.Gomez@Empresa.com ', $serial, 'Tesorería', 'Grupo 1', 'Analista'],
            ['Luis', 'Pérez', 'luis@empresa.com', '05/03/2027', 'tesoreria', 'GRUPO 1', 'Líder'],
            ['Eva', 'Ruiz', 'eva@empresa.com', '', 'Tesorería', 'Grupo 1', 'Analista'],
        ]))->assertSee('Al confirmar se guardarán 3 participantes.');

        $this->confirmar()->assertSessionHas('exito', 'Se cargaron en Empresa Uno: 3 participantes.');

        $ana = Participante::where('nombre', 'Ana María')->sole();
        $this->assertSame('ana.gomez@empresa.com', $ana->correo);
        $this->assertSame('2027-01-15', $ana->fecha_ingreso->format('Y-m-d'));
        $this->assertSame($this->grupo1->id_grupo, $ana->id_grupo);
        $this->assertSame('Analista', $ana->rol);
        $this->assertSame('2027-03-05', Participante::where('nombre', 'Luis')->sole()->fecha_ingreso->format('Y-m-d'));
        $this->assertNull(Participante::where('nombre', 'Eva')->sole()->fecha_ingreso);
    }

    public function test_un_correo_existente_en_cualquier_empresa_es_duplicado(): void
    {
        $otra = Empresa::create(['nombre' => 'Otra', 'sigla' => 'OTR']);
        $grupoAjeno = Grupo::create(['id_bloque' => Bloque::create(['id_empresa' => $otra->id_empresa, 'nombre' => 'B'])->id_bloque, 'nombre' => 'G']);
        Participante::create(['id_grupo' => $grupoAjeno->id_grupo, 'nombre' => 'Ya', 'apellidos' => 'Existe', 'correo' => 'ya@existe.com', 'rol' => 'Analista']);

        $this->revisar($this->archivo([], [
            // Duplicado: no se revisan sus otros datos porque no se va a insertar.
            ['Ya', 'Existe', 'YA@existe.com', '', 'Bloque que no existe', 'X', 'Analista'],
            ['Nueva', 'Persona', 'nueva@empresa.com', '', 'Tesorería', 'Grupo 1', 'Analista'],
        ]))
            ->assertSee('Ya existe un participante con este correo: no se vuelve a insertar.')
            ->assertSee('Confirmar y guardar');

        $this->confirmar()->assertSessionHas('exito', 'Se cargaron en Empresa Uno: 1 participante. Se omitió 1 participante duplicado.');

        $this->assertSame(2, Participante::count());
        $this->assertSame('Ya', Participante::where('correo', 'ya@existe.com')->sole()->nombre);
    }

    public function test_un_correo_repetido_en_el_archivo_es_error(): void
    {
        $this->revisar($this->archivo([], [
            ['Ana', 'Gómez', 'ana@empresa.com', '', 'Tesorería', 'Grupo 1', 'Analista'],
            ['Ana', 'Otra', ' ANA@empresa.com', '', 'Tesorería', 'Grupo 1', 'Analista'],
        ]))
            ->assertSee('El correo se repite en la fila 3.')
            ->assertSee('El correo se repite en la fila 2.')
            ->assertDontSee('Confirmar y guardar');
    }

    public function test_valida_los_datos_del_participante(): void
    {
        Grupo::create(['id_bloque' => $this->tesoreria->id_bloque, 'nombre' => 'Grupo viejo', 'estado' => false]);

        $this->revisar($this->archivo([], [
            ['', '', 'no-es-correo', '31/02/2026', 'Tesorería', 'Grupo 9', str_repeat('r', 51)],
            ['Ana', 'Gómez', 'ana@empresa.com', '2027-01-15', 'Tesorería', 'Grupo viejo', ''],
            ['Eva', 'Ruiz', '', 'ayer', 'Tesorería', '', 'Analista'],
        ]))
            ->assertSee('Falta el nombre.')
            ->assertSee('Faltan los apellidos.')
            ->assertSee('El correo «no-es-correo» no es válido.')
            ->assertSee('La fecha de ingreso «31/02/2026» no es válida: usa el formato dd/mm/aaaa.')
            ->assertSee('La fecha de ingreso «2027-01-15» no es válida')
            ->assertSee('La fecha de ingreso «ayer» no es válida')
            ->assertSee('El grupo «Grupo 9» no existe en el bloque Tesorería.')
            ->assertSee('El grupo Grupo viejo está dado de baja.')
            ->assertSee('El rol no puede tener más de 50 caracteres.')
            ->assertSee('Falta el rol.')
            ->assertSee('Falta el correo.')
            ->assertSee('Falta el grupo.')
            ->assertDontSee('Confirmar y guardar');
    }

    // ===== Archivos temporales =====

    public function test_cancelar_borra_el_archivo_y_la_vista_previa(): void
    {
        $this->subir($this->archivo([['', '', '', 'Tarea', 'Tesorería', 'Onboarding']]));
        $this->assertCount(1, $this->archivosPendientes());

        $this->actingAs($this->admin)->post('/carga/cancelar')
            ->assertRedirect(route('carga.index'))
            ->assertSessionHas('exito', 'Se canceló la carga. No se guardó nada.');

        $this->assertSame([], $this->archivosPendientes());
        $this->actingAs($this->admin)->get('/carga/vista-previa')
            ->assertRedirect(route('carga.index'))
            ->assertSessionHas('error', 'La vista previa ya no está disponible. Vuelve a subir el archivo.');
        $this->confirmar()->assertRedirect(route('carga.index'));
        $this->assertSame(0, Actividad::count());
    }

    public function test_subir_otro_archivo_borra_el_anterior(): void
    {
        $this->subir($this->archivo([['', '', '', 'Primera', 'Tesorería', 'Onboarding']]));
        $primero = $this->archivosPendientes();

        $this->subir($this->archivo([['', '', '', 'Segunda', 'Tesorería', 'Onboarding']]));

        $this->assertCount(1, $this->archivosPendientes());
        $this->assertNotSame($primero, $this->archivosPendientes());
        $this->actingAs($this->admin)->get('/carga/vista-previa')->assertSee('Segunda')->assertDontSee('Primera');
    }

    public function test_se_borran_los_archivos_sin_confirmar_de_mas_de_24_horas(): void
    {
        $disco = Storage::disk('local');
        $disco->put('cargas/viejo.xlsx', 'x');
        $disco->put('cargas/reciente.xlsx', 'x');
        touch($disco->path('cargas/viejo.xlsx'), now()->subHours(25)->getTimestamp());

        $this->artisan('cargas:limpiar')->expectsOutput('Archivos de carga borrados: 1.')->assertSuccessful();

        $this->assertSame(['cargas/reciente.xlsx'], $disco->files('cargas'));

        // Abrir la pantalla de carga también limpia, por si la tarea programada no corre.
        touch($disco->path('cargas/reciente.xlsx'), now()->subDays(2)->getTimestamp());
        $this->actingAs($this->admin)->get('/carga')->assertOk();
        $this->assertSame([], $disco->files('cargas'));
    }

    // ===== Base de datos =====

    public function test_la_base_rechaza_una_actividad_sin_bloque(): void
    {
        $this->expectException(QueryException::class);

        Actividad::create(['id_empresa' => $this->empresa->id_empresa, 'tipo' => TipoActividad::Onboarding, 'nombre' => 'Sin bloque']);
    }
}
