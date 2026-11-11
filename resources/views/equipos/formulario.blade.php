{{--
    Editar el equipo de un proceso. Al guardar se actualizan todos sus seguimientos (también los de baja).
    Si el proceso está de baja o todavía no tiene seguimientos, la pantalla es solo de consulta.
--}}
@extends('layouts.app')

@php($titulo = $editable ? 'Editar equipo del proceso' : 'Consultar equipo del proceso')

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            <p class="pagina-subtitulo">{{ $proceso->nombre }} · {{ $proceso->empresa->nombre }}</p>
        </div>
    </div>

    @if (! $proceso->estado)
        <x-alerta tipo="advertencia">El proceso está dado de baja: su equipo solo se puede consultar.</x-alerta>
    @elseif ($total === 0)
        <x-alerta tipo="advertencia">Este proceso todavía no tiene seguimientos: el equipo se podrá definir cuando los tenga.</x-alerta>
    @elseif ($distintos->isNotEmpty())
        <x-alerta tipo="advertencia">
            Hay valores distintos en: {{ $distintos->implode(', ') }}. Al guardar, todos quedan iguales.
        </x-alerta>
    @endif

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ route('equipos.actualizar', $proceso) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="volver_a" value="{{ $volverA }}">

            <fieldset @disabled(! $editable)>
                @foreach (\App\Models\Seguimiento::CAMPOS_DEL_PROCESO as $campo => $etiqueta)
                    <x-campo :name="$campo" :label="$etiqueta" :value="old($campo, $valores[$campo])" maxlength="255" />
                @endforeach
            </fieldset>

            @if ($editable)
                <p class="texto-secundario small mb-0">
                    Se {{ $total === 1 ? 'actualizará 1 seguimiento' : "actualizarán {$total} seguimientos" }} del proceso.
                </p>
            @endif

            <div class="formulario-acciones">
                <a href="{{ $volverA }}" class="btn btn-outline-primary">{{ $editable ? 'Cancelar' : 'Volver' }}</a>
                @if ($editable)
                    <x-boton>Guardar</x-boton>
                @endif
            </div>
        </form>
    </div>
@endsection
