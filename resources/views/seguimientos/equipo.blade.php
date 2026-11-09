{{-- Equipo del bloque: estos datos se copian a todos los seguimientos del bloque. --}}
@extends('layouts.app')

@php
    $editable = $bloque->estado && $totalSeguimientos > 0;
@endphp

@section('titulo', 'Equipo del bloque')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Equipo del bloque {{ $bloque->nombre }}</h1>
            <p class="pagina-subtitulo">Aplica a todos los seguimientos del bloque.</p>
        </div>
    </div>

    @if (! $bloque->estado)
        <x-alerta tipo="advertencia">El bloque está dado de baja: su equipo solo se puede consultar.</x-alerta>
    @elseif ($totalSeguimientos === 0)
        <x-alerta tipo="advertencia">Este bloque todavía no tiene seguimientos.</x-alerta>
    @elseif ($distintos->isNotEmpty())
        <x-alerta tipo="advertencia">
            Hay valores distintos en: {{ $distintos->implode(', ') }}. Al guardar, todos quedan iguales.
        </x-alerta>
    @endif

    <div class="tarjeta tarjeta-formulario">
        <dl class="datos-fijos mb-3">
            <div>
                <dt>Empresa</dt>
                <dd>{{ $bloque->empresa->nombre }}</dd>
            </div>
            <div>
                <dt>Seguimientos del bloque</dt>
                <dd>{{ $totalSeguimientos }}</dd>
            </div>
        </dl>

        <form method="POST" action="{{ route('seguimientos.equipo.actualizar', $bloque) }}">
            @csrf
            @method('PUT')

            <fieldset @disabled(! $editable)>
                <div class="row">
                    @foreach (\App\Models\Seguimiento::CAMPOS_DEL_BLOQUE as $campo => $etiqueta)
                        <div class="col-sm-6">
                            <x-campo :name="$campo" :label="$etiqueta.' (opcional)'" :value="old($campo, $equipo[$campo])" maxlength="255" />
                        </div>
                    @endforeach
                </div>
            </fieldset>

            @if ($editable)
                <p class="texto-secundario small mb-0">
                    Se actualizarán {{ $totalSeguimientos }} seguimientos.
                </p>
            @endif

            <div class="formulario-acciones">
                <a href="{{ route('seguimientos.index', ['id_bloque' => $bloque->id_bloque]) }}" class="btn btn-outline-primary">Volver a seguimientos</a>
                @if ($editable)
                    <x-boton>Guardar</x-boton>
                @endif
            </div>
        </form>
    </div>
@endsection
