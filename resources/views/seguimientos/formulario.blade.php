{{--
    Editar un seguimiento. Actividad, grupo y fase quedan fijos.
    Si el grupo o la actividad están de baja, o si su fase todavía no se habilita para el grupo
    ($bloqueo, ver App\Services\AvanceDeFases), la pantalla es solo de consulta.
--}}
@extends('layouts.app')

@php
    $enTrabajo = $seguimiento->estaEnTrabajo();
    $editable = $enTrabajo && $bloqueo === null;
    $titulo = $editable ? 'Editar seguimiento' : 'Consultar seguimiento';
    // A dónde volver: la lista con los filtros con los que se abrió esta pantalla.
    $lista = route('seguimientos.index');
    $anterior = url()->previous();
    $volverA = old('volver_a', $anterior === $lista || str_starts_with($anterior, $lista.'?') ? $anterior : $lista);
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            <p class="pagina-subtitulo">{{ $seguimiento->actividad->nombre }}</p>
        </div>
    </div>

    @unless ($enTrabajo)
        <x-alerta tipo="advertencia">El grupo o la actividad están dados de baja: este seguimiento solo se puede consultar.</x-alerta>
    @endunless

    @if ($bloqueo)
        <x-fase-bloqueada :mensaje="$bloqueo" />
    @endif

    <div class="tarjeta tarjeta-seguimiento">
        <dl class="datos-fijos">
            @if (filled($seguimiento->actividad->macroproceso))
                <div>
                    <dt>Macroproceso</dt>
                    <dd>{{ $seguimiento->actividad->macroproceso }}</dd>
                </div>
            @endif
            <div>
                <dt>Grupo</dt>
                <dd>
                    {{ $seguimiento->grupo->proceso->nombre }} › {{ $seguimiento->grupo->nombre }}
                    <a href="{{ route('grupos.fases', $seguimiento->grupo) }}" class="ms-1">Ver fases del grupo</a>
                    {{-- El equipo es uno por proceso: se ve y se cambia en la lista filtrada por el proceso. --}}
                    · <a href="{{ route('seguimientos.index', ['id_proceso' => $seguimiento->grupo->id_proceso]) }}#equipo">Ver equipo del proceso</a>
                </dd>
            </div>
            <div>
                <dt>Empresa</dt>
                <dd>{{ $seguimiento->grupo->proceso->empresa->nombre }}</dd>
            </div>
            <div>
                <dt>Fase</dt>
                <dd>{{ $seguimiento->fase->nombre }}</dd>
            </div>
            <div>
                <dt>Estado actual</dt>
                <dd><x-estado-seguimiento :seguimiento="$seguimiento" /></dd>
            </div>
            <div>
                <dt>Fecha de cierre</dt>
                <dd>{{ $seguimiento->fecha_cierre?->format('d/m/Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt>Último cambio</dt>
                <dd>{{ $seguimiento->autor?->nombre ?? '—' }}</dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('seguimientos.actualizar', $seguimiento) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="volver_a" value="{{ $volverA }}">

            {{-- disabled: en modo consulta el navegador no deja escribir; el servidor también lo impide. --}}
            <fieldset @disabled(! $editable)>
                {{-- Cuadrícula de Bootstrap: en pantallas medianas o más grandes los campos van en columnas; en el celular se apilan. --}}
                <div class="row">
                    <div class="col-md-4">
                        <x-campo name="fecha_inicio" label="Fecha de inicio" type="date" :value="old('fecha_inicio', $seguimiento->fecha_inicio?->format('Y-m-d'))" />
                    </div>
                    <div class="col-md-4">
                        <x-campo name="fecha_fin" label="Fecha fin (propuesta de cierre)" type="date" :value="old('fecha_fin', $seguimiento->fecha_fin?->format('Y-m-d'))" />
                    </div>
                    <div class="col-md-4">
                        <x-lista name="estado_actividad" label="Estado" :opciones="\App\Enums\EstadoActividad::opciones()" :value="old('estado_actividad', $seguimiento->estadoActual()->value)" vacia="Elige un estado" required />
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <x-campo name="documentacion" label="Documentación (opcional)" :value="old('documentacion', $seguimiento->documentacion)" maxlength="500" />
                        {{-- Es texto libre: solo se ofrece abrirla si es una dirección web. --}}
                        @if (! $editable && preg_match('#^https?://#i', (string) $seguimiento->documentacion))
                            <p class="ayuda-campo"><a href="{{ $seguimiento->documentacion }}" target="_blank" rel="noopener noreferrer">Abrir la documentación</a></p>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <x-campo name="experto" label="Experto (opcional)" :value="old('experto', $seguimiento->experto)" maxlength="255" />
                    </div>
                </div>

                <div class="mb-3">
                    <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                    <textarea id="comentarios" name="comentarios" rows="4" maxlength="10000"
                        @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                        @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios', $seguimiento->comentarios) }}</textarea>
                    @error('comentarios')
                        <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </fieldset>

            <div class="formulario-acciones">
                <a href="{{ $volverA }}" class="btn btn-outline-primary">{{ $editable ? 'Cancelar' : 'Volver' }}</a>
                @if ($editable)
                    <x-boton>Guardar</x-boton>
                @endif
            </div>
        </form>
    </div>

    {{-- Sesiones: solo en fases con tiene_sesiones = 1. Las demás (Validación) registran avances. --}}
    @if ($seguimiento->tieneSesiones())
        <div class="tarjeta mt-3">
            <div class="asistencia-encabezado">
                <h2 class="tarjeta-titulo mb-0">Sesiones</h2>
                @if ($editable && $seguimiento->tieneFechas())
                    <a href="{{ route('sesiones.crear', $seguimiento) }}" class="btn btn-sm btn-primary">Nueva sesión</a>
                @endif
            </div>

            {{-- Las sesiones deben quedar dentro de las fechas del seguimiento. --}}
            @if ($editable && ! $seguimiento->tieneFechas())
                <p class="texto-secundario px-3 mb-2">Para registrar sesiones, primero guarda la fecha de inicio y la fecha fin.</p>
            @endif

            <div class="table-responsive">
                <table class="table tabla-apilada">
                    <thead>
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Tema</th>
                            <th scope="col">Modalidad</th>
                            <th scope="col">Asistencia</th>
                            <th scope="col">Capturó</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($seguimiento->sesiones as $sesion)
                            <tr>
                                <td class="celda-titulo">{{ $sesion->fecha->format('d/m/Y') }}</td>
                                <td class="celda-detalle" data-etiqueta="Tema">{{ $sesion->tema }}</td>
                                <td class="celda-detalle" data-etiqueta="Modalidad">{{ $sesion->modalidad->value }}</td>
                                <td class="celda-detalle" data-etiqueta="Asistencia">{{ $sesion->asistentes_count }} de {{ $sesion->asistencias_count }}</td>
                                <td class="celda-detalle" data-etiqueta="Capturó">{{ $sesion->autor?->nombre ?? '—' }}</td>
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        <a href="{{ route('sesiones.editar', $sesion) }}" class="btn btn-sm btn-outline-primary">{{ $editable ? 'Editar' : 'Consultar' }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="tabla-vacia">Todavía no hay sesiones.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        {{-- Avances de validación: en las fases sin sesiones. El % actual es la captura más reciente. --}}
        <div class="tarjeta mt-3">
            <div class="asistencia-encabezado">
                <h2 class="tarjeta-titulo mb-0">Avances de validación</h2>
                @if ($editable && $seguimiento->tieneFechas())
                    <a href="{{ route('avances.crear', $seguimiento) }}" class="btn btn-sm btn-primary">Nuevo avance</a>
                @endif
            </div>

            @if ($editable && ! $seguimiento->tieneFechas())
                <p class="texto-secundario px-3 mb-2">Para registrar avances, primero guarda la fecha de inicio y la fecha fin.</p>
            @endif

            @if ($seguimiento->avances->isNotEmpty())
                @php($actual = $seguimiento->avances->first())
                <p class="px-3 mb-2"><strong>% actual: {{ $actual->porcentaje_real }} %</strong> <span class="texto-secundario">(esperado {{ $actual->porcentaje_esperado }} %, corte al {{ $actual->fecha_corte->format('d/m/Y') }})</span></p>
            @endif

            <div class="table-responsive">
                <table class="table tabla-apilada">
                    <thead>
                        <tr>
                            <th scope="col">Fecha de corte</th>
                            <th scope="col">% esperado</th>
                            <th scope="col">% real</th>
                            <th scope="col">Capturó</th>
                            <th scope="col">Comentarios</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($seguimiento->avances as $avance)
                            <tr>
                                <td class="celda-titulo">{{ $avance->fecha_corte->format('d/m/Y') }}</td>
                                <td class="celda-detalle" data-etiqueta="% esperado">{{ $avance->porcentaje_esperado }} %</td>
                                <td class="celda-detalle" data-etiqueta="% real">{{ $avance->porcentaje_real }} %</td>
                                <td class="celda-detalle" data-etiqueta="Capturó">{{ $avance->usuario->nombre }}</td>
                                <td class="celda-detalle" data-etiqueta="Comentarios">{{ \Illuminate\Support\Str::limit($avance->comentarios ?? '—', 80) }}</td>
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        <a href="{{ route('avances.editar', $avance) }}" class="btn btn-sm btn-outline-primary">{{ $editable ? 'Editar' : 'Consultar' }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="tabla-vacia">Todavía no hay avances.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
