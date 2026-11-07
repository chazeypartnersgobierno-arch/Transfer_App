{{-- Se usa para crear y para editar. Al editar solo cambia el nombre: el bloque queda fijo. --}}
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
                <p class="pagina-subtitulo">Al guardar se generan sus seguimientos. Elige bien el bloque: después no se puede cambiar.</p>
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
                <x-lista name="id_bloque" label="Bloque" :opciones="$opcionesDeBloque" :value="old('id_bloque')" vacia="Elige un bloque" required />
            @endif

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $grupo->nombre)" maxlength="255" required />

            @unless ($esNuevo)
                <dl class="datos-fijos">
                    <div>
                        <dt>Bloque</dt>
                        <dd>{{ $grupo->bloque->nombre }}</dd>
                    </div>
                    <div>
                        <dt>Empresa</dt>
                        <dd>{{ $grupo->bloque->empresa->nombre }}</dd>
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
