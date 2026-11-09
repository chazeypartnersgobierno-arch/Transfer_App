<?php

namespace App\Exports;

use App\Enums\TipoActividad;
use App\Models\Bloque;
use App\Models\Empresa;
use App\Models\Grupo;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Plantilla .xlsx para la carga desde Excel de una empresa.
 * Las listas desplegables traen sus bloques y grupos activos al momento de descargarla.
 * En Laravel Excel 4, un libro de varias hojas también se marca como Export.
 */
class PlantillaDeCarga implements Export, WithMultipleSheets
{
    /** Valor de la columna Bloque para una actividad que aplica a todos los bloques. */
    public const TODOS = 'Todos';

    public const ENCABEZADOS_ACTIVIDADES = ['Macroproceso', 'Proceso', 'Subproceso', 'Actividad', 'Bloque', 'Tipo'];

    public const ENCABEZADOS_PARTICIPANTES = ['Nombre', 'Apellidos', 'Correo', 'Fecha de ingreso', 'Bloque', 'Grupo', 'Rol'];

    public function __construct(private Empresa $empresa) {}

    public function sheets(): array
    {
        $bloques = Bloque::where('id_empresa', $this->empresa->id_empresa)
            ->where('estado', true)
            ->orderBy('nombre')
            ->pluck('nombre');

        // Un mismo nombre de grupo puede existir en varios bloques: en la lista va una vez.
        $grupos = Grupo::where('estado', true)
            ->whereHas('bloque', fn ($q) => $q->where('id_empresa', $this->empresa->id_empresa)->where('estado', true))
            ->orderBy('nombre')
            ->pluck('nombre')
            ->unique()
            ->values();

        $tipos = collect(TipoActividad::cases())->pluck('value');

        // Columnas de la hoja oculta Listas: A bloques con "Todos", B bloques, C grupos, D tipos.
        $columnasDeListas = [
            ['Bloques para actividades', collect([self::TODOS])->merge($bloques)],
            ['Bloques', $bloques],
            ['Grupos', $grupos],
            ['Tipos', $tipos],
        ];

        return [
            $this->instrucciones(),
            new HojaDePlantilla(
                'Actividades',
                [self::ENCABEZADOS_ACTIVIDADES],
                listas: [
                    'E' => $this->rango('A', $columnasDeListas[0][1]),
                    'F' => $this->rango('D', $tipos),
                ],
                anchos: ['A' => 28, 'B' => 28, 'C' => 28, 'D' => 60, 'E' => 28, 'F' => 16],
            ),
            new HojaDePlantilla(
                'Participantes',
                [self::ENCABEZADOS_PARTICIPANTES],
                listas: array_filter([
                    'E' => $this->rango('B', $bloques),
                    'F' => $this->rango('C', $grupos),
                ]),
                anchos: ['A' => 24, 'B' => 28, 'C' => 34, 'D' => 18, 'E' => 28, 'F' => 24, 'G' => 24],
            ),
            new HojaDePlantilla(
                'Listas',
                $this->filasDeListas($columnasDeListas),
                oculta: true,
                conEncabezado: false,
            ),
        ];
    }

    private function instrucciones(): HojaDePlantilla
    {
        $filas = [
            ["Plantilla de carga · {$this->empresa->nombre} ({$this->empresa->sigla})"],
            [],
            ['Cómo usarla'],
            ['1. Llena las hojas Actividades y Participantes, una fila por registro, sin cambiar los encabezados ni el nombre de las hojas.'],
            ['2. Usa las listas desplegables: traen los bloques y grupos activos al momento de descargar la plantilla.'],
            ['   Si después agregas bloques o grupos en la app, descarga una plantilla nueva.'],
            ['3. Sube el archivo en la pantalla "Carga desde Excel". Antes de guardar verás nuevos, duplicados y errores por hoja y fila.'],
            ['4. Si hay errores no se guarda nada: corrígelos en el archivo y vuelve a subirlo.'],
            [],
            ['Reglas'],
            ['• Tipo Onboarding: la actividad genera 1 seguimiento por grupo, en la fase Onboarding.'],
            ['• Tipo Transición: la actividad pasa por las tres fases (Transferencia, Estabilización y Validación) y genera 3 seguimientos por grupo.'],
            ['• Bloque "Todos": la actividad aplica a todos los grupos de la empresa, por ejemplo una inducción general.'],
            ['• Las actividades se cargan una sola vez por bloque y tipo: si el bloque ya tiene actividades de ese tipo, se rechaza la carga.'],
            ['  Los cambios posteriores se hacen en la app.'],
            ['• Las actividades se guardan en el orden de las filas. Las filas repetidas son error.'],
            ['• Un participante cuyo correo ya existe se marca como duplicado y no se vuelve a insertar.'],
            ['• Las fechas de cada actividad no van aquí: se capturan en la app, en cada seguimiento.'],
            [],
            ['Hoja', 'Columna', 'Obligatoria', 'Qué va', 'Ejemplo'],
            ['Actividades', 'Macroproceso', 'No', 'Macroproceso al que pertenece la actividad.', 'Finanzas'],
            ['Actividades', 'Proceso', 'No', 'Proceso dentro del macroproceso.', 'Tesorería'],
            ['Actividades', 'Subproceso', 'No', 'Subproceso; puede quedar vacío.', 'Conciliaciones'],
            ['Actividades', 'Actividad', 'Sí', 'Nombre de la actividad (hasta 500 caracteres).', 'Conciliar cuentas bancarias'],
            ['Actividades', 'Bloque', 'Sí', 'Bloque de la lista, o "Todos".', 'Tesorería'],
            ['Actividades', 'Tipo', 'Sí', 'Onboarding o Transición.', 'Transición'],
            ['Participantes', 'Nombre', 'Sí', 'Nombre o nombres.', 'Ana María'],
            ['Participantes', 'Apellidos', 'Sí', 'Apellidos.', 'Gómez Ruiz'],
            ['Participantes', 'Correo', 'Sí', 'Correo único de la persona.', 'ana.gomez@empresa.com'],
            ['Participantes', 'Fecha de ingreso', 'No', 'Fecha con formato día/mes/año.', '15/01/2027'],
            ['Participantes', 'Bloque', 'Sí', 'Bloque del grupo, de la lista.', 'Tesorería'],
            ['Participantes', 'Grupo', 'Sí', 'Grupo de ese bloque, de la lista.', 'Grupo 1'],
            ['Participantes', 'Rol', 'Sí', 'Rol de la persona, en texto libre (hasta 50 caracteres).', 'Analista'],
        ];

        return new class($filas) extends HojaDePlantilla
        {
            public function __construct(array $filas)
            {
                parent::__construct('Instrucciones', $filas, anchos: ['A' => 16, 'B' => 20, 'C' => 13, 'D' => 70, 'E' => 30], conEncabezado: false);
            }

            public function registerEvents(): array
            {
                $eventos = parent::registerEvents();
                $original = $eventos[AfterSheet::class];

                $eventos[AfterSheet::class] = function (AfterSheet $evento) use ($original) {
                    $original($evento);

                    $hoja = $evento->sheet->getDelegate();
                    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1B396A');
                    $hoja->getStyle('A3')->getFont()->setBold(true);
                    $hoja->getStyle('A10')->getFont()->setBold(true);
                    $hoja->getStyle('A20:E20')->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1B396A']],
                    ]);
                };

                return $eventos;
            }
        };
    }

    /**
     * Rango de la hoja Listas con los valores de una columna, o vacío si no hay valores.
     */
    private function rango(string $columna, $valores): string
    {
        return count($valores) > 0 ? "\${$columna}\$2:\${$columna}\$".(count($valores) + 1) : '';
    }

    /**
     * Arma las filas de la hoja Listas: encabezados y una columna por lista.
     */
    private function filasDeListas(array $columnas): array
    {
        $filas = [array_column($columnas, 0)];
        $alto = max(array_map(fn ($c) => count($c[1]), $columnas));

        for ($i = 0; $i < $alto; $i++) {
            $filas[] = array_map(fn ($c) => $c[1][$i] ?? '', $columnas);
        }

        return $filas;
    }
}
