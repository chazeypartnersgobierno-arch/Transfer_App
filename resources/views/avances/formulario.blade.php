{{--
    Crear o editar un avance de validación de un seguimiento (un grupo a la vez).
    Si el grupo o la actividad están de baja, solo se consulta.
--}}
@extends('layouts.app')

@php
    $esNuevo = ! $avance->exists;
    $editable = $seguimiento->estaEnTrabajo();
    $titulo = $esNuevo ? 'Nuevo avance' : ($editable ? 'Editar avance' : 'Consultar avance');
    // El calendario solo deja elegir fechas del seguimiento y nunca después de hoy. El servidor también lo valida.
    $maximo = $seguimiento->fecha_fin === null ? null : $seguimiento->fecha_fin->min(today());
    $existente = session('avance_existente');
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            <p class="pagina-subtitulo">{{ $seguimiento->actividad->nombre }} · {{ $seguimiento->grupo->nombre }} · {{ $seguimiento->fase->nombre }}</p>
        </div>
    </div>

    @unless ($editable)
        <x-alerta tipo="advertencia">El grupo o la actividad están dados de baja: este avance solo se puede consultar.</x-alerta>
    @endunless

    @if ($existente)
        <x-alerta tipo="advertencia">Ya hay un avance con esa fecha de corte. <a href="{{ route('avances.editar', $existente) }}">Abrir el avance existente para corregirlo</a>.</x-alerta>
    @endif

    <div class="tarjeta tarjeta-formulario">
        @unless ($esNuevo)
            <p class="texto-secundario">Última captura o corrección: {{ $avance->usuario->nombre }}.</p>
        @endunless

        <form method="POST" action="{{ $esNuevo ? route('avances.guardar', $seguimiento) : route('avances.actualizar', $avance) }}">
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            <fieldset @disabled(! $editable)>
                <x-campo name="fecha_corte" label="Fecha de corte" type="date" :value="old('fecha_corte', $avance->fecha_corte?->format('Y-m-d'))" required
                    :min="$seguimiento->fecha_inicio?->format('Y-m-d')" :max="$maximo?->format('Y-m-d')" />
                <p class="ayuda-campo">Debe estar entre las fechas del seguimiento ({{ $seguimiento->rangoDeFechas() }}) y no puede ser futura.</p>

                <div class="row">
                    <div class="col-sm-6">
                        <x-lista name="porcentaje_esperado" label="% esperado" :opciones="\App\Models\AvanceValidacion::opcionesEsperado()" :value="old('porcentaje_esperado', $avance->porcentaje_esperado)" vacia="Elige el % esperado" required />
                    </div>
                    <div class="col-sm-6">
                        <x-campo name="porcentaje_real" label="% real (de 0 a 100)" type="number" :value="old('porcentaje_real', $avance->porcentaje_real)" min="0" max="100" step="1" required />
                    </div>
                </div>

                <div class="mb-3">
                    <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                    <textarea id="comentarios" name="comentarios" rows="3" maxlength="10000"
                        @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                        @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios', $avance->comentarios) }}</textarea>
                    @error('comentarios')
                        <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </fieldset>

            <div class="formulario-acciones">
                <a href="{{ route('seguimientos.editar', $seguimiento) }}" class="btn btn-outline-primary">{{ $editable ? 'Cancelar' : 'Volver' }}</a>
                @if ($editable)
                    <x-boton>Guardar</x-boton>
                @endif
            </div>
        </form>
    </div>
@endsection
