<?php

namespace App\Services;

use App\Enums\TipoActividad;
use App\Exports\PlantillaDeCarga;
use App\Imports\LectorDeCarga;
use App\Models\Actividad;
use App\Models\Proceso;
use App\Models\Empresa;
use App\Models\Grupo;
use App\Models\Participante;
use App\Support\Texto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Carga desde Excel en dos pasos:
 * 1. revisar(): lee el archivo y clasifica cada fila (nueva, duplicado o error) sin guardar nada.
 * 2. guardar(): inserta lo nuevo, todo en una transacción.
 *
 * Los textos se comparan sin importar mayúsculas, acentos ni espacios de más (App\Support\Texto).
 */
class CargaDesdeExcel
{
    public function __construct(private GeneradorDeSeguimientos $generador) {}

    public function revisar(Empresa $empresa, string $ruta): ResultadoDeCarga
    {
        $resultado = new ResultadoDeCarga;

        try {
            $hojas = Excel::toArray(new LectorDeCarga, $ruta);
        } catch (Throwable) {
            $resultado->erroresGenerales[] = 'No se pudo leer el archivo. Revisa que sea un .xlsx válido, por ejemplo la plantilla descargada en esta pantalla.';

            return $resultado;
        }

        $actividades = $this->leerHoja($resultado, $hojas, 'Actividades', PlantillaDeCarga::ENCABEZADOS_ACTIVIDADES);
        $participantes = $this->leerHoja($resultado, $hojas, 'Participantes', PlantillaDeCarga::ENCABEZADOS_PARTICIPANTES);

        if ($resultado->erroresGenerales !== []) {
            return $resultado;
        }

        if ($actividades === [] && $participantes === []) {
            $resultado->erroresGenerales[] = 'El archivo no tiene datos: las hojas Actividades y Participantes están vacías.';

            return $resultado;
        }

        // Todos los procesos de la empresa, también los de baja, para decir si "no existe" o "está de baja".
        $procesos = Proceso::where('id_empresa', $empresa->id_empresa)
            ->with('grupos')
            ->get()
            ->keyBy(fn (Proceso $proceso) => Texto::llave($proceso->nombre));

        $this->revisarActividades($resultado, $empresa, $actividades, $procesos);
        $this->revisarParticipantes($resultado, $participantes, $procesos);

        return $resultado;
    }

    /**
     * Inserta las filas nuevas en el orden del archivo y genera los seguimientos de las
     * actividades. Todo o nada: si algo falla, no queda nada guardado.
     *
     * @return array{actividades: int, seguimientos: int, participantes: int}
     */
    public function guardar(ResultadoDeCarga $resultado): array
    {
        return DB::transaction(function () use ($resultado) {
            $totales = ['actividades' => 0, 'seguimientos' => 0, 'participantes' => 0];

            foreach ($resultado->porGuardar('Actividades') as $datos) {
                $actividad = Actividad::create($datos);
                $actividad->refresh(); // trae estado = 1, el valor por defecto de la base

                $totales['actividades']++;
                $totales['seguimientos'] += $this->generador->generarParaActividad($actividad);
            }

            foreach ($resultado->porGuardar('Participantes') as $datos) {
                Participante::create($datos);
                $totales['participantes']++;
            }

            return $totales;
        });
    }

    /**
     * Devuelve las filas con datos de una hoja, cada una con su número de fila en Excel y
     * sus valores por encabezado. Las columnas se buscan por nombre, así que su orden no
     * importa y las columnas de más (por ejemplo ID o fechas) se ignoran.
     *
     * @return list<array{fila: int, valores: array<string, mixed>}>
     */
    private function leerHoja(ResultadoDeCarga $resultado, array $hojas, string $hoja, array $encabezados): array
    {
        if (! array_key_exists($hoja, $hojas)) {
            $resultado->erroresGenerales[] = "Falta la hoja {$hoja}. Usa la plantilla descargada en esta pantalla.";

            return [];
        }

        $filas = $hojas[$hoja];
        $primera = array_map(fn ($valor) => Texto::llave($valor), $filas[0] ?? []);

        // La plantilla anterior tenía una columna Bloque (hoy proceso) y otra Proceso de texto libre.
        // Ahora la columna Proceso trae el proceso: si viene la columna Bloque se rechaza,
        // para no tomar el texto libre viejo como proceso.
        if (in_array(Texto::llave('Bloque'), $primera, true)) {
            $resultado->erroresGenerales[] = "La hoja {$hoja} tiene la columna Bloque: es una plantilla anterior. Descarga la plantilla nueva en esta pantalla.";

            return [];
        }

        // Columna en la que está cada encabezado esperado.
        $columnas = [];
        $faltan = [];

        foreach ($encabezados as $encabezado) {
            $columna = array_search(Texto::llave($encabezado), $primera, true);

            if ($columna === false) {
                $faltan[] = $encabezado;
            } else {
                $columnas[$encabezado] = $columna;
            }
        }

        if ($faltan !== []) {
            $resultado->erroresGenerales[] = "En la hoja {$hoja} faltan las columnas: ".implode(', ', $faltan).'.';

            return [];
        }

        $datos = [];

        foreach (array_slice($filas, 1, null, true) as $indice => $fila) {
            $valores = [];

            foreach ($columnas as $encabezado => $columna) {
                $valores[$encabezado] = $fila[$columna] ?? null;
            }

            // Una fila sin nada escrito se salta, aunque tenga formato.
            $vacia = collect($valores)->every(fn ($valor) => Texto::limpiar($valor) === '');

            if (! $vacia) {
                $datos[] = ['fila' => $indice + 1, 'valores' => $valores];
            }
        }

        return $datos;
    }

    /**
     * @param  Collection<string, Proceso>  $procesos
     */
    private function revisarActividades(ResultadoDeCarga $resultado, Empresa $empresa, array $filas, Collection $procesos): void
    {
        $revisadas = [];
        $vistas = []; // llave de la fila => número de la primera fila igual

        foreach ($filas as ['fila' => $numero, 'valores' => $valores]) {
            $macroproceso = Texto::limpiar($valores['Macroproceso']);
            $nombre = Texto::limpiar($valores['Actividad']);
            // La columna Proceso trae el nombre del proceso.
            $textoProceso = Texto::limpiar($valores['Proceso']);
            $textoTipo = Texto::limpiar($valores['Tipo']);

            $errores = $this->revisarLargo('El macroproceso', $macroproceso, 255);

            if ($nombre === '') {
                $errores[] = 'Falta la actividad.';
            } else {
                $errores = [...$errores, ...$this->revisarLargo('La actividad', $nombre, 500)];
            }

            $proceso = $this->buscarProceso($textoProceso, $procesos, $errores, conTodos: true);

            $tipo = null;
            if ($textoTipo === '') {
                $errores[] = 'Falta el tipo.';
            } else {
                $tipo = collect(TipoActividad::cases())->first(fn (TipoActividad $t) => Texto::llave($t->value) === Texto::llave($textoTipo));

                if ($tipo === null) {
                    $errores[] = "El tipo «{$textoTipo}» no es válido: usa Onboarding o Transición.";
                }
            }

            $llave = implode('|', array_map(fn ($v) => Texto::llave($v), [$textoProceso, $textoTipo, $macroproceso, $nombre]));

            if (isset($vistas[$llave])) {
                $errores[] = "Repite la fila {$vistas[$llave]}.";
            } else {
                $vistas[$llave] = $numero;
            }

            $revisadas[] = [
                'fila' => $numero,
                'mostrar' => [
                    'Actividad' => $nombre,
                    'Macroproceso' => $macroproceso,
                    'Proceso' => $textoProceso,
                    'Tipo' => $tipo?->value ?? $textoTipo,
                ],
                'errores' => $errores,
                'proceso' => $proceso,
                'tipo' => $tipo,
                'guardar' => [
                    'id_empresa' => $empresa->id_empresa,
                    'id_proceso' => $proceso?->id_proceso,
                    'tipo' => $tipo?->value,
                    'macroproceso' => $macroproceso === '' ? null : $macroproceso,
                    'nombre' => $nombre,
                ],
            ];
        }

        $yaCargadas = $this->actividadesExistentes($empresa, $revisadas);

        // Grupos activos por proceso, para contar los seguimientos que se generarán.
        $gruposActivos = Grupo::where('estado', true)
            ->whereIn('id_proceso', collect($revisadas)->pluck('proceso.id_proceso')->filter()->unique())
            ->selectRaw('id_proceso, COUNT(*) AS total')
            ->groupBy('id_proceso')
            ->pluck('total', 'id_proceso');

        foreach ($revisadas as $revisada) {
            $errores = $revisada['errores'];
            $proceso = $revisada['proceso'];
            $tipo = $revisada['tipo'];

            if ($proceso !== null && $tipo !== null && isset($yaCargadas["{$proceso->id_proceso}|{$tipo->value}"])) {
                $errores[] = "El proceso {$proceso->nombre} ya tiene actividades de tipo {$tipo->value}.";
            }

            if ($errores === []) {
                $resultado->seguimientosPorGenerar += ($gruposActivos[$proceso->id_proceso] ?? 0) * count($tipo->ordenesDeFase());
            }

            $resultado->agregarFila('Actividades', $revisada['fila'], $revisada['mostrar'], $errores, $revisada['guardar']);
        }

        foreach ($yaCargadas as $existente) {
            $resultado->erroresGenerales[] = "El proceso {$existente['proceso']} ya tiene {$existente['total']} "
                .($existente['total'] === 1 ? 'actividad' : 'actividades')
                ." de tipo {$existente['tipo']} (se cuentan también las dadas de baja y las creadas a mano). "
                .'Las actividades se cargan una sola vez por proceso y tipo: los cambios se hacen en la pantalla de Actividades.';
        }
    }

    /**
     * Combinaciones de proceso y tipo del archivo que ya tienen actividades en la base,
     * con una sola consulta.
     *
     * @return array<string, array{proceso: string, tipo: string, total: int}> Llave "id_proceso|tipo".
     */
    private function actividadesExistentes(Empresa $empresa, array $revisadas): array
    {
        $procesosDelArchivo = collect($revisadas)
            ->filter(fn ($r) => $r['proceso'] !== null && $r['tipo'] !== null)
            ->mapWithKeys(fn ($r) => ["{$r['proceso']->id_proceso}|{$r['tipo']->value}" => $r['proceso']]);

        if ($procesosDelArchivo->isEmpty()) {
            return [];
        }

        return Actividad::where('id_empresa', $empresa->id_empresa)
            ->whereIn('id_proceso', $procesosDelArchivo->pluck('id_proceso')->unique())
            ->selectRaw('id_proceso, tipo, COUNT(*) AS total')
            ->groupBy('id_proceso', 'tipo')
            ->toBase() // filas simples: sin convertir tipo al enum
            ->get()
            ->filter(fn ($fila) => $procesosDelArchivo->has("{$fila->id_proceso}|{$fila->tipo}"))
            ->mapWithKeys(fn ($fila) => ["{$fila->id_proceso}|{$fila->tipo}" => [
                'proceso' => $procesosDelArchivo["{$fila->id_proceso}|{$fila->tipo}"]->nombre,
                'tipo' => $fila->tipo,
                'total' => (int) $fila->total,
            ]])
            ->all();
    }

    /**
     * @param  Collection<string, Proceso>  $procesos
     */
    private function revisarParticipantes(ResultadoDeCarga $resultado, array $filas, Collection $procesos): void
    {
        // Primero, cuántas veces aparece cada correo en el archivo.
        $filasPorCorreo = [];
        foreach ($filas as ['fila' => $numero, 'valores' => $valores]) {
            $correo = Texto::llave($valores['Correo']);
            if ($correo !== '') {
                $filasPorCorreo[$correo][] = $numero;
            }
        }

        // Correos que ya existen en la base, en cualquier empresa: una sola consulta.
        $existentes = Participante::whereIn('correo', array_map(fn ($c) => mb_strtolower(Texto::limpiar($c)), array_column(array_column($filas, 'valores'), 'Correo')))
            ->pluck('correo')
            ->mapWithKeys(fn ($correo) => [Texto::llave($correo) => true]);

        foreach ($filas as ['fila' => $numero, 'valores' => $valores]) {
            $nombre = Texto::limpiar($valores['Nombre']);
            $apellidos = Texto::limpiar($valores['Apellidos']);
            $correo = mb_strtolower(Texto::limpiar($valores['Correo']));
            $textoProceso = Texto::limpiar($valores['Proceso']);
            $textoGrupo = Texto::limpiar($valores['Grupo']);
            $rol = Texto::limpiar($valores['Rol']);

            // Un correo repetido dentro del archivo es error, aunque ya exista en la base.
            $repetido = null;
            $otras = array_diff($filasPorCorreo[Texto::llave($correo)] ?? [], [$numero]);
            if ($otras !== []) {
                $repetido = 'El correo se repite en '.(count($otras) === 1 ? 'la fila ' : 'las filas ').implode(', ', $otras).'.';
            }

            // Si el correo ya existe en la base, la fila no se inserta: es un duplicado, no un error,
            // y sus demás datos no se revisan porque no se van a usar.
            if ($correo !== '' && $existentes->has(Texto::llave($correo))) {
                $resultado->agregarFila('Participantes', $numero, $this->mostrarParticipante($valores), $repetido ? [$repetido] : [], null,
                    'Ya existe un participante con este correo: no se vuelve a insertar.');

                continue;
            }

            $errores = [
                ...$this->revisarObligatorio('Falta el nombre.', 'El nombre', $nombre, 255),
                ...$this->revisarObligatorio('Faltan los apellidos.', 'Los apellidos', $apellidos, 255),
            ];

            if ($correo === '') {
                $errores[] = 'Falta el correo.';
            } elseif (mb_strlen($correo) > 255 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
                $errores[] = "El correo «{$correo}» no es válido.";
            } elseif ($repetido !== null) {
                $errores[] = $repetido;
            }

            $fecha = $this->fecha($valores['Fecha de ingreso'], $errores);

            $grupo = null;
            $proceso = $this->buscarProceso($textoProceso, $procesos, $errores);

            if ($textoGrupo === '') {
                $errores[] = 'Falta el grupo.';
            } elseif ($proceso !== null) {
                $grupo = $proceso->grupos->first(fn (Grupo $g) => Texto::llave($g->nombre) === Texto::llave($textoGrupo));

                if ($grupo === null) {
                    $errores[] = "El grupo «{$textoGrupo}» no existe en el proceso {$proceso->nombre}.";
                } elseif (! $grupo->estado) {
                    $errores[] = "El grupo {$grupo->nombre} está dado de baja.";
                    $grupo = null;
                }
            }

            $errores = [...$errores, ...$this->revisarObligatorio('Falta el rol.', 'El rol', $rol, 50)];

            $resultado->agregarFila('Participantes', $numero, $this->mostrarParticipante($valores), $errores, [
                'id_grupo' => $grupo?->id_grupo,
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'correo' => $correo,
                'rol' => $rol,
                'fecha_ingreso' => $fecha,
            ]);
        }
    }

    /**
     * Datos de un participante para la vista previa.
     *
     * @return array<string, string>
     */
    private function mostrarParticipante(array $valores): array
    {
        $proceso = Texto::limpiar($valores['Proceso']);
        $grupo = Texto::limpiar($valores['Grupo']);

        return [
            'Nombre' => trim(Texto::limpiar($valores['Nombre']).' '.Texto::limpiar($valores['Apellidos'])),
            'Correo' => mb_strtolower(Texto::limpiar($valores['Correo'])),
            'Grupo' => $proceso.($grupo !== '' ? " › {$grupo}" : ''),
            'Rol' => Texto::limpiar($valores['Rol']),
        ];
    }

    /**
     * Busca el proceso por nombre. Distingue entre "no existe" y "está dado de baja".
     *
     * @param  Collection<string, Proceso>  $procesos
     */
    private function buscarProceso(string $texto, Collection $procesos, array &$errores, bool $conTodos = false): ?Proceso
    {
        if ($texto === '') {
            $errores[] = 'Falta el proceso.';

            return null;
        }

        $proceso = $procesos->get(Texto::llave($texto));

        if ($proceso === null) {
            // "Todos" se usaba antes: ahora cada actividad lleva un proceso real.
            $errores[] = $conTodos && Texto::llave($texto) === 'todos'
                ? 'Ya no se usa «Todos»: cada actividad lleva un proceso de la empresa.'
                : "El proceso «{$texto}» no existe en esta empresa.";

            return null;
        }

        if (! $proceso->estado) {
            $errores[] = "El proceso {$proceso->nombre} está dado de baja.";

            return null;
        }

        return $proceso;
    }

    /**
     * Fecha de ingreso (opcional). Acepta una fecha de Excel o un texto dd/mm/aaaa.
     * Una fecha que no existe, como 31/02/2026, es error.
     */
    private function fecha(mixed $valor, array &$errores): ?string
    {
        if (Texto::limpiar($valor) === '') {
            return null;
        }

        // Una celda con formato de fecha llega como número: los días desde el 1/1/1900.
        if (is_int($valor) || is_float($valor)) {
            if ($valor >= 1 && $valor < 2958466) {
                return Date::excelToDateTimeObject(floor($valor))->format('Y-m-d');
            }

            $errores[] = 'La fecha de ingreso no es válida.';

            return null;
        }

        $texto = Texto::limpiar($valor);

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $texto, $partes) && checkdate((int) $partes[2], (int) $partes[1], (int) $partes[3])) {
            return sprintf('%04d-%02d-%02d', $partes[3], $partes[2], $partes[1]);
        }

        $errores[] = "La fecha de ingreso «{$texto}» no es válida: usa el formato dd/mm/aaaa.";

        return null;
    }

    /** @return list<string> */
    private function revisarObligatorio(string $siFalta, string $campo, string $texto, int $maximo): array
    {
        return $texto === '' ? [$siFalta] : $this->revisarLargo($campo, $texto, $maximo);
    }

    /** @return list<string> */
    private function revisarLargo(string $campo, string $texto, int $maximo): array
    {
        return mb_strlen($texto) > $maximo ? ["{$campo} no puede tener más de {$maximo} caracteres."] : [];
    }
}
