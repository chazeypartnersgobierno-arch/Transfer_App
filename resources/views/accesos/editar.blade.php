{{--
    Editar una solicitud: estado, fechas y comentarios. El participante y el aplicativo quedan fijos.
    La fecha otorgado solo se usa en Otorgado (public/js/app.js la oculta en los demás estados).
--}}
@extends('layouts.app')

@php
    $fechaOtorgado = old('fecha_otorgado', ($acceso->fecha_otorgado ?? today())->format('Y-m-d'));
    $estadoElegido = old('estado', $acceso->estado->value);
@endphp

@section('titulo', 'Solicitud de acceso')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Solicitud de {{ $acceso->aplicativo->nombre }}</h1>
            <p class="pagina-subtitulo">{{ $acceso->participante->nombreCompleto() }} · {{ $acceso->participante->grupo->bloque->nombre }} › {{ $acceso->participante->grupo->nombre }}</p>
        </div>
    </div>

    @unless ($editable)
        <x-alerta tipo="advertencia">El participante o el aplicativo están dados de baja: esta solicitud solo se puede consultar.</x-alerta>
    @endunless

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ route('accesos.actualizar', $acceso) }}">
            @csrf
            @method('PUT')

            <fieldset @disabled(! $editable)>
                <div class="row">
                    <div class="col-sm-6">
                        <x-lista name="estado" label="Estado" :opciones="\App\Enums\EstadoAcceso::opciones()" :value="$estadoElegido" vacia="Elige un estado" required data-estado-acceso />
                    </div>
                    <div class="col-sm-6">
                        <x-campo name="fecha_solicitud" label="Fecha de solicitud" type="date" :value="old('fecha_solicitud', $acceso->fecha_solicitud->format('Y-m-d'))" :max="today()->format('Y-m-d')" required />
                    </div>
                </div>

                <div data-solo-otorgado @if ($estadoElegido !== \App\Enums\EstadoAcceso::Otorgado->value) hidden @endif>
                    <x-campo name="fecha_otorgado" label="Fecha otorgado" type="date" :value="$fechaOtorgado" :max="today()->format('Y-m-d')" />
                </div>

                <div class="mb-3">
                    <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                    <textarea id="comentarios" name="comentarios" rows="3" maxlength="10000"
                        @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                        @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios', $acceso->comentarios) }}</textarea>
                    @error('comentarios')
                        <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </fieldset>

            <div class="formulario-acciones">
                <a href="{{ route('accesos.index', ['id_participante' => $acceso->id_participante]) }}" class="btn btn-outline-primary">{{ $editable ? 'Cancelar' : 'Volver' }}</a>
                @if ($editable)
                    <x-boton>Guardar</x-boton>
                @endif
            </div>
        </form>
    </div>
@endsection
