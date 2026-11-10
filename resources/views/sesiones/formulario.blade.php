{{--
    Crear o editar una sesión con su asistencia.
    Al crear se eligen uno o varios grupos: se crea una sesión por grupo.
    Al editar se corrige una sola sesión (un grupo). Si el grupo o la actividad están de baja, solo se consulta.
--}}
@extends('layouts.app')

@php
    $esNueva = ! $sesion->exists;
    $enTrabajo = $seguimiento->estaEnTrabajo();
    // $bloqueo: la fase todavía no se habilita para el grupo (ver App\Services\AvanceDeFases). Solo se consulta.
    $editable = $enTrabajo && $bloqueo === null;
    $titulo = $esNueva ? 'Nueva sesión' : ($editable ? 'Editar sesión' : 'Consultar sesión');
    $repetidas = session('repetidas', []);
    // Al crear, el grupo de origen viene elegido; después de un error, lo que se había elegido.
    $elegidos = collect(old('seguimientos', [$seguimiento->id_seguimiento]))->map(fn ($id) => (int) $id);
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            <p class="pagina-subtitulo">{{ $seguimiento->actividad->nombre }} · {{ $seguimiento->fase->nombre }}</p>
            @if (! $esNueva && $sesion->autor)
                <p class="texto-secundario mb-0">Último cambio: {{ $sesion->autor->nombre }}.</p>
            @endif
        </div>
    </div>

    @unless ($enTrabajo)
        <x-alerta tipo="advertencia">El grupo o la actividad están dados de baja: esta sesión solo se puede consultar.</x-alerta>
    @endunless

    @if ($bloqueo)
        <x-fase-bloqueada :mensaje="$bloqueo" />
    @endif

    @if ($repetidas !== [])
        <x-alerta tipo="advertencia">
            Ya hay una sesión con la fecha {{ \Illuminate\Support\Carbon::parse(old('fecha'))->format('d/m/Y') }} en: {{ implode(', ', $repetidas) }}.
            Si es otra sesión, usa «Guardar de todos modos».
        </x-alerta>
    @endif

    <form method="POST" action="{{ $esNueva ? route('sesiones.guardar', $seguimiento) : route('sesiones.actualizar', $sesion) }}">
        @csrf
        @unless ($esNueva)
            @method('PUT')
        @endunless

        <fieldset @disabled(! $editable)>
            <div class="tarjeta tarjeta-formulario mb-3">
                <h2 class="tarjeta-titulo">Datos de la sesión</h2>

                <div class="row">
                    <div class="col-sm-6">
                        {{-- min y max: el calendario solo deja elegir fechas del seguimiento. El servidor también lo valida. --}}
                        <x-campo name="fecha" label="Fecha" type="date" :value="old('fecha', $sesion->fecha?->format('Y-m-d'))" required
                            :min="$seguimiento->fecha_inicio?->format('Y-m-d')" :max="$seguimiento->fecha_fin?->format('Y-m-d')" data-fecha-sesion />
                    </div>
                    <div class="col-sm-6">
                        <x-lista name="modalidad" label="Modalidad" :opciones="\App\Enums\Modalidad::opciones()" :value="old('modalidad', $sesion->modalidad?->value)" vacia="Elige una modalidad" required />
                    </div>
                </div>

                <x-campo name="tema" label="Tema" :value="old('tema', $sesion->tema)" maxlength="255" required />

                <div class="mb-0">
                    <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                    <textarea id="comentarios" name="comentarios" rows="3" maxlength="10000"
                        @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                        @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios', $sesion->comentarios) }}</textarea>
                    @error('comentarios')
                        <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if ($esNueva)
                <div class="tarjeta tarjeta-formulario mb-3">
                    <h2 class="tarjeta-titulo">Grupos</h2>
                    <p class="texto-secundario">
                        Elige uno o varios grupos.
                    </p>

                    @error('seguimientos')
                        <x-alerta tipo="error">{{ $message }}</x-alerta>
                    @enderror
                    @error('seguimientos.*')
                        <x-alerta tipo="error">{{ $message }}</x-alerta>
                    @enderror

                    <div class="lista-grupos">
                        @foreach ($opciones as $opcion)
                            {{-- Un grupo sin fechas o con la fase bloqueada aparece, pero no se puede elegir. --}}
                            @php($sePuedeElegir = $opcion->tieneFechas() && ! isset($bloqueos[$opcion->id_seguimiento]))
                            <div class="form-check">
                                <input id="grupo_{{ $opcion->id_seguimiento }}" type="checkbox" name="seguimientos[]" value="{{ $opcion->id_seguimiento }}" class="form-check-input" data-elegir-grupo @checked($sePuedeElegir && $elegidos->contains($opcion->id_seguimiento)) @disabled(! $sePuedeElegir)>
                                <label for="grupo_{{ $opcion->id_seguimiento }}" class="form-check-label">
                                    {{ $opcion->grupo->nombre }}
                                    <span class="texto-secundario">({{ $opcion->grupo->participantes->count() }} {{ $opcion->grupo->participantes->count() === 1 ? 'participante' : 'participantes' }})</span>
                                    <span class="d-block texto-secundario small">{{ ucfirst($opcion->rangoDeFechas()) }}</span>
                                    @isset($bloqueos[$opcion->id_seguimiento])
                                        <span class="d-block texto-secundario small"><x-icono nombre="candado" :tamano="14" /> {{ $bloqueos[$opcion->id_seguimiento] }}</span>
                                    @endisset
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($opciones as $opcion)
                @include('sesiones.asistencia', [
                    'opcion' => $opcion,
                    'asistencias' => $asistencias,
                    'fechaSesion' => old('fecha', $sesion->fecha?->format('Y-m-d')),
                ])
            @endforeach
        </fieldset>

        <div class="formulario-acciones mb-3">
            <a href="{{ route('seguimientos.editar', $seguimiento) }}" class="btn btn-outline-primary">{{ $editable ? 'Cancelar' : 'Volver' }}</a>
            @if ($editable)
                @if ($repetidas !== [])
                    <x-boton name="confirmar_repetida" value="1">Guardar de todos modos</x-boton>
                @else
                    <x-boton>Guardar</x-boton>
                @endif
            @endif
        </div>
    </form>
@endsection
