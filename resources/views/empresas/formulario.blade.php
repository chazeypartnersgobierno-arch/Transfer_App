{{-- Se usa para crear y para editar: una empresa nueva todavía no existe en la base de datos. --}}
@extends('layouts.app')

@php
    $esNueva = ! $empresa->exists;
    $titulo = $esNueva ? 'Nueva empresa' : 'Editar empresa';
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">{{ $titulo }}</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ $esNueva ? route('empresas.guardar') : route('empresas.actualizar', $empresa) }}">
            @csrf
            {{-- Los navegadores solo envían GET y POST; así Laravel lo recibe como PUT. --}}
            @unless ($esNueva)
                @method('PUT')
            @endunless

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $empresa->nombre)" maxlength="255" required autofocus />
            <x-campo name="sigla" label="Sigla" :value="old('sigla', $empresa->sigla)" maxlength="20" required />

            <div class="formulario-acciones">
                <a href="{{ route('empresas.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
