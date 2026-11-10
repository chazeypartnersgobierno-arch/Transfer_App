{{-- Se usa para crear y para editar. Al editar solo cambia el nombre: el proceso queda fijo. --}}
@extends('layouts.app')

@php
    $esNuevo = ! $grupo->exists;
    $titulo = $esNuevo ? 'Nuevo grupo' : 'Editar grupo';
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            @if ($esNuevo)
                <p class="pagina-subtitulo">El proceso no se puede cambiar después.</p>
            @endif
        </div>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ $esNuevo ? route('grupos.guardar') : route('grupos.actualizar', $grupo) }}">
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            @if ($esNuevo)
                <x-lista name="id_proceso" label="Proceso" :opciones="$opcionesDeProceso" :value="old('id_proceso')" vacia="Elige un proceso" required />
            @endif

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $grupo->nombre)" maxlength="255" required />

            @unless ($esNuevo)
                <dl class="datos-fijos">
                    <div>
                        <dt>Proceso</dt>
                        <dd>{{ $grupo->proceso->nombre }}</dd>
                    </div>
                    <div>
                        <dt>Empresa</dt>
                        <dd>{{ $grupo->proceso->empresa->nombre }}</dd>
                    </div>
                </dl>
            @endunless

            <div class="formulario-acciones">
                <a href="{{ route('grupos.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
