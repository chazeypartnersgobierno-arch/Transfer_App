<?php

namespace App\Http\Controllers;

use App\Enums\Modalidad;
use App\Models\Asistencia;
use App\Models\Participante;
use App\Models\Seguimiento;
use App\Models\Sesion;
use App\Models\Usuario;
use App\Services\AvanceDeFases;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sesiones y asistencia de un seguimiento. Admin y consultor (Gate dar-seguimiento).
 *
 * - Una sesión se captura una vez y se eligen uno o varios grupos: se crea una sesión por
 *   grupo (por seguimiento), cada una con su propia asistencia, todo en una transacción.
 * - Las sesiones no se borran: se corrigen editándolas.
 * - Si ya hay una sesión con la misma fecha en el seguimiento, se avisa y se pide confirmación.
 * - Solo en fases habilitadas para el grupo (la anterior debe estar completa, ver AvanceDeFases).
 *
 * La asistencia viaja en el formulario como asistencia[id_seguimiento][id_participante][asistio|comentarios].
 */
class SesionController extends Controller
{
    public function crear(Request $request, Seguimiento $seguimiento): View|RedirectResponse
    {
        if ($motivo = $this->motivoParaNoCapturar($seguimiento)) {
            return redirect()->route('seguimientos.editar', $seguimiento)->with('error', $motivo);
        }

        $opciones = $this->seguimientosParaElegir($request->user(), $seguimiento);

        return view('sesiones.formulario', [
            'sesion' => new Sesion,
            'seguimiento' => $seguimiento,
            'opciones' => $opciones,
            'asistencias' => collect(),
            'bloqueo' => null,
            // Grupos de la lista cuya fase todavía no se habilita: se muestran sin poder elegirlos.
            'bloqueos' => $this->bloqueosDe($opciones),
        ]);
    }

    public function guardar(Request $request, Seguimiento $seguimiento): RedirectResponse
    {
        if ($motivo = $this->motivoParaNoCapturar($seguimiento)) {
            return redirect()->route('seguimientos.editar', $seguimiento)->with('error', $motivo);
        }

        // toBase(): una colección simple, para que only() conserve las llaves (id_seguimiento).
        $opciones = $this->seguimientosParaElegir($request->user(), $seguimiento)->toBase()->keyBy('id_seguimiento');

        $datos = $request->validate([
            // Solo grupos de la misma actividad y fase, en trabajo, de su empresa y con fechas.
            'seguimientos' => ['required', 'array'],
            'seguimientos.*' => ['integer', Rule::in($opciones->filter(fn (Seguimiento $s) => $s->tieneFechas())->keys()->all())],
            ...$this->reglas(),
        ], $this->mensajes());

        $elegidos = $opciones->only(array_map('intval', $datos['seguimientos']));

        // Cada grupo avanza por su cuenta: la fase debe estar habilitada en todos los elegidos.
        $bloqueados = $this->bloqueosDe($elegidos);

        if ($bloqueados !== []) {
            throw ValidationException::withMessages([
                'seguimientos' => 'La fase todavía no está habilitada para: '
                    .$elegidos->only(array_keys($bloqueados))->map(fn (Seguimiento $s) => "{$s->grupo->nombre} ({$bloqueados[$s->id_seguimiento]})")->implode(' '),
            ]);
        }

        // Cada grupo tiene sus propias fechas: la sesión debe caer dentro de las de todos.
        $this->validarRango($datos['fecha'], $elegidos);

        $repetidas = Sesion::whereIn('id_seguimiento', $elegidos->keys())
            ->whereDate('fecha', $datos['fecha'])
            ->pluck('id_seguimiento')
            ->unique();

        if ($repetidas->isNotEmpty() && ! $request->boolean('confirmar_repetida')) {
            return redirect()->route('sesiones.crear', $seguimiento)
                ->withInput()
                ->with('repetidas', $elegidos->only($repetidas->all())->map(fn (Seguimiento $s) => $s->grupo->nombre)->values()->all());
        }

        // Todo o nada: si una sesión o asistencia falla, no queda ninguna.
        DB::transaction(function () use ($elegidos, $datos, $request) {
            foreach ($elegidos as $elegido) {
                $sesion = Sesion::create([
                    ...$this->datosDeSesion($datos),
                    'id_seguimiento' => $elegido->id_seguimiento,
                    'id_usuario' => $request->user()->id_usuario,
                ]);
                $this->guardarAsistencia($sesion, $elegido->grupo->participantes, $request->input("asistencia.{$elegido->id_seguimiento}", []));
            }
        });

        $grupos = $elegidos->count() === 1 ? 'en 1 grupo' : "en {$elegidos->count()} grupos";

        return redirect()->route('seguimientos.editar', $seguimiento)
            ->with('exito', "Se registró la sesión del {$this->fechaCorta($datos['fecha'])} {$grupos}.");
    }

    /**
     * Si el grupo o la actividad están de baja, la misma pantalla sirve solo para consultar.
     */
    public function editar(Sesion $sesion): View
    {
        $seguimiento = $sesion->seguimiento->load(['actividad', 'grupo.bloque.empresa', 'fase']);
        $asistencias = $sesion->asistencias()->get()->keyBy('id_participante');
        $seguimiento->grupo->setRelation('participantes', $this->participantesDeLaSesion($sesion, $asistencias->keys()->all()));

        return view('sesiones.formulario', [
            'sesion' => $sesion,
            'seguimiento' => $seguimiento,
            'opciones' => new Collection([$seguimiento]),
            'asistencias' => $asistencias,
            'bloqueo' => app(AvanceDeFases::class)->bloqueoDe($seguimiento),
            'bloqueos' => [],
        ]);
    }

    public function actualizar(Request $request, Sesion $sesion): RedirectResponse
    {
        $seguimiento = $sesion->seguimiento;

        if (! $seguimiento->estaEnTrabajo()) {
            return redirect()->route('sesiones.editar', $sesion)
                ->with('error', 'Esta sesión es de un grupo o una actividad dados de baja: solo se puede consultar.');
        }

        if ($bloqueo = app(AvanceDeFases::class)->bloqueoDe($seguimiento)) {
            return redirect()->route('sesiones.editar', $sesion)->with('error', $bloqueo);
        }

        if (! $seguimiento->tieneFechas()) {
            return redirect()->route('sesiones.editar', $sesion)
                ->with('error', 'Primero captura la fecha de inicio y la fecha fin del seguimiento.');
        }

        $datos = $request->validate($this->reglas(), $this->mensajes());
        $this->validarRango($datos['fecha'], collect([$seguimiento]));

        $repetida = Sesion::where('id_seguimiento', $seguimiento->id_seguimiento)
            ->whereKeyNot($sesion->id_sesion)
            ->whereDate('fecha', $datos['fecha'])
            ->exists();

        if ($repetida && ! $request->boolean('confirmar_repetida')) {
            return redirect()->route('sesiones.editar', $sesion)
                ->withInput()
                ->with('repetidas', [$seguimiento->grupo->nombre]);
        }

        $participantes = $this->participantesDeLaSesion($sesion, $sesion->asistencias()->pluck('id_participante')->all());

        DB::transaction(function () use ($sesion, $datos, $participantes, $request, $seguimiento) {
            // id_usuario queda con quien hizo la última corrección.
            $sesion->update([...$this->datosDeSesion($datos), 'id_usuario' => $request->user()->id_usuario]);
            $this->guardarAsistencia($sesion, $participantes, $request->input("asistencia.{$seguimiento->id_seguimiento}", []));
        });

        return redirect()->route('seguimientos.editar', $seguimiento)
            ->with('exito', "Se actualizó la sesión del {$this->fechaCorta($datos['fecha'])}.");
    }

    /**
     * Por qué no se pueden capturar sesiones en este seguimiento, o null si sí se puede.
     */
    private function motivoParaNoCapturar(Seguimiento $seguimiento): ?string
    {
        if (! $seguimiento->tieneSesiones()) {
            return "La fase {$seguimiento->fase->nombre} no registra sesiones.";
        }

        if (! $seguimiento->estaEnTrabajo()) {
            return 'Este seguimiento es de un grupo o una actividad dados de baja: solo se puede consultar.';
        }

        if ($bloqueo = app(AvanceDeFases::class)->bloqueoDe($seguimiento)) {
            return $bloqueo;
        }

        if (! $seguimiento->tieneFechas()) {
            return 'Primero captura la fecha de inicio y la fecha fin del seguimiento: las sesiones deben quedar dentro de esas fechas.';
        }

        return null;
    }

    /**
     * Grupos en los que se puede registrar la misma sesión: los seguimientos de la misma
     * actividad y fase que están en trabajo. El seguimiento de origen va primero.
     * Cada uno trae los participantes activos de su grupo.
     *
     * @return Collection<int, Seguimiento>
     */
    private function seguimientosParaElegir(Usuario $usuario, Seguimiento $seguimiento): Collection
    {
        return Seguimiento::visiblePara($usuario)
            ->enTrabajo()
            ->where('id_actividad', $seguimiento->id_actividad)
            ->where('id_fase', $seguimiento->id_fase)
            ->with([
                'grupo',
                'grupo.participantes' => fn ($q) => $q->where('estado', true)->orderBy('apellidos')->orderBy('nombre'),
            ])
            ->get()
            ->sortBy(fn (Seguimiento $s) => [$s->id_seguimiento !== $seguimiento->id_seguimiento, $s->grupo->nombre])
            ->values();
    }

    /**
     * Mensaje de bloqueo de cada seguimiento cuya fase no está habilitada para su grupo: id_seguimiento => mensaje.
     *
     * @param  \Illuminate\Support\Collection<int, Seguimiento>  $seguimientos
     * @return array<int, string>
     */
    private function bloqueosDe(\Illuminate\Support\Collection $seguimientos): array
    {
        $avance = app(AvanceDeFases::class);

        return $seguimientos
            ->mapWithKeys(fn (Seguimiento $s) => [$s->id_seguimiento => $avance->bloqueoDe($s)])
            ->filter()
            ->all();
    }

    /**
     * Participantes de la asistencia de una sesión: los activos de su grupo (también quien
     * entró después) y quien ya tiene registro aunque esté de baja, para no perderlo de vista.
     *
     * @param  list<int>  $conRegistro
     */
    private function participantesDeLaSesion(Sesion $sesion, array $conRegistro): Collection
    {
        return Participante::where('id_grupo', $sesion->seguimiento->id_grupo)
            ->where(fn ($q) => $q->where('estado', true)->orWhereIn('id_participante', $conRegistro))
            ->orderBy('apellidos')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Un registro por participante: asistió sí o no. Solo se leen los participantes
     * esperados; cualquier otro id que llegue en el formulario se ignora.
     */
    private function guardarAsistencia(Sesion $sesion, Collection $participantes, mixed $entrada): void
    {
        $entrada = is_array($entrada) ? $entrada : [];

        foreach ($participantes as $participante) {
            $fila = is_array($entrada[$participante->id_participante] ?? null) ? $entrada[$participante->id_participante] : [];
            $comentarios = trim((string) ($fila['comentarios'] ?? ''));

            Asistencia::updateOrCreate(
                ['id_sesion' => $sesion->id_sesion, 'id_participante' => $participante->id_participante],
                ['asistio' => ! empty($fila['asistio']), 'comentarios' => $comentarios === '' ? null : $comentarios],
            );
        }
    }

    /**
     * La fecha de la sesión debe estar entre la fecha de inicio y la fecha fin de cada
     * seguimiento elegido (ambas incluidas). Si no, error en el campo fecha.
     *
     * @param  \Illuminate\Support\Collection<int, Seguimiento>  $seguimientos
     */
    private function validarRango(string $fecha, \Illuminate\Support\Collection $seguimientos): void
    {
        $fuera = $seguimientos->reject(fn (Seguimiento $s) => $s->fechaDentroDelRango($fecha));

        if ($fuera->isNotEmpty()) {
            throw ValidationException::withMessages([
                'fecha' => 'La fecha queda fuera de las fechas del seguimiento: '
                    .$fuera->map(fn (Seguimiento $s) => "{$s->grupo->nombre} ({$s->rangoDeFechas()})")->implode(', ').'.',
            ]);
        }
    }

    private function datosDeSesion(array $datos): array
    {
        return [
            'fecha' => $datos['fecha'],
            'tema' => $datos['tema'],
            'modalidad' => $datos['modalidad'],
            'comentarios' => $datos['comentarios'] ?? null,
        ];
    }

    private function reglas(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'tema' => ['required', 'string', 'max:255'],
            'modalidad' => ['required', Rule::enum(Modalidad::class)],
            'comentarios' => ['nullable', 'string', 'max:10000'],
            'asistencia' => ['nullable', 'array'],
            'asistencia.*.*.comentarios' => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'seguimientos.required' => 'Elige al menos un grupo.',
            'seguimientos.*.in' => 'Elige grupos de la lista.',
            'fecha.required' => 'Escribe la fecha de la sesión.',
            'fecha.date' => 'Escribe una fecha válida.',
            'tema.required' => 'Escribe el tema de la sesión.',
            'tema.max' => 'El tema no puede tener más de 255 caracteres.',
            'modalidad.required' => 'Elige una modalidad.',
            'modalidad.enum' => 'Elige una modalidad de la lista.',
            'comentarios.max' => 'Los comentarios no pueden tener más de 10,000 caracteres.',
            'asistencia.*.*.comentarios.max' => 'El comentario no puede tener más de 10,000 caracteres.',
        ];
    }

    private function fechaCorta(string $fecha): string
    {
        return date('d/m/Y', strtotime($fecha));
    }
}
