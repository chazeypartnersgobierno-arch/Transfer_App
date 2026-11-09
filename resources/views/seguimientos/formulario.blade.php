{{--
    Editar un seguimiento. Actividad, grupo y fase quedan fijos.
    Si el grupo o la actividad están de baja, la pantalla es solo de consulta.
--}}
@extends('layouts.app')

@php
    $editable = $seguimiento->estaEnTrabajo();
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

    @unless ($editable)
        <x-alerta tipo="advertencia">El grupo o la actividad están dados de baja: este seguimiento solo se puede consultar.</x-alerta>
    @endunless

    <div class="tarjeta tarjeta-formulario">
        <dl class="datos-fijos mb-3">
            @if ($seguimiento->actividad->rutaDeProceso() !== '')
                <div>
                    <dt>Proceso</dt>
                    <dd>{{ $seguimiento->actividad->rutaDeProceso() }}</dd>
                </div>
            @endif
            <div>
                <dt>Grupo</dt>
                <dd>{{ $seguimiento->grupo->bloque->nombre }} › {{ $seguimiento->grupo->nombre }}</dd>
            </div>
            <div>
                <dt>Empresa</dt>
                <dd>{{ $seguimiento->grupo->bloque->empresa->nombre }}</dd>
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
                <dd>{{ $seguimiento->fecha_cierre?->format('d/m/Y') ?? 'Se llena sola al pasar a Completada' }}</dd>
            </div>
        </dl>

        {{-- Equipo del bloque: igual en todos sus seguimientos, por eso aquí solo se muestra. --}}
        <div class="equipo-bloque mb-3">
            <div class="equipo-bloque-encabezado">
                <h2 class="tarjeta-titulo mb-0">Equipo del bloque {{ $seguimiento->grupo->bloque->nombre }}</h2>
                <a href="{{ route('seguimientos.equipo', $seguimiento->grupo->bloque) }}" class="btn btn-sm btn-outline-primary">{{ $seguimiento->grupo->bloque->estado ? 'Cambiar equipo' : 'Ver equipo' }}</a>
            </div>
            <dl class="datos-fijos mb-0">
                @foreach (\App\Models\Seguimiento::CAMPOS_DEL_BLOQUE as $campo => $etiqueta)
                    <div>
                        <dt>{{ $etiqueta }}</dt>
                        <dd>{{ $seguimiento->{$campo} ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <form method="POST" action="{{ route('seguimientos.actualizar', $seguimiento) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="volver_a" value="{{ $volverA }}">

            {{-- disabled: en modo consulta el navegador no deja escribir; el servidor también lo impide. --}}
            <fieldset @disabled(! $editable)>
                <div class="row">
                    <div class="col-sm-6">
                        <x-campo name="fecha_inicio" label="Fecha de inicio" type="date" :value="old('fecha_inicio', $seguimiento->fecha_inicio?->format('Y-m-d'))" />
                    </div>
                    <div class="col-sm-6">
                        <x-campo name="fecha_fin" label="Fecha fin (propuesta de cierre)" type="date" :value="old('fecha_fin', $seguimiento->fecha_fin?->format('Y-m-d'))" />
                    </div>
                </div>

                <x-lista name="estado_actividad" label="Estado" :opciones="\App\Enums\EstadoActividad::opciones()" :value="old('estado_actividad', $seguimiento->estadoActual()->value)" vacia="Elige un estado" required />

                <x-campo name="documentacion" label="Documentación (opcional)" :value="old('documentacion', $seguimiento->documentacion)" maxlength="500" />
                {{-- Es texto libre: solo se ofrece abrirla si es una dirección web. --}}
                @if (! $editable && preg_match('#^https?://#i', (string) $seguimiento->documentacion))
                    <p class="ayuda-campo"><a href="{{ $seguimiento->documentacion }}" target="_blank" rel="noopener noreferrer">Abrir la documentación</a></p>
                @endif

                <x-campo name="experto" label="Experto (opcional)" :value="old('experto', $seguimiento->experto)" maxlength="255" />

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
@endsection
