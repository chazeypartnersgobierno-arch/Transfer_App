{{-- Se usa para crear y para editar. Al editar no se puede cambiar la empresa. --}}
@extends('layouts.app')

@php
    $esNuevo = ! $proceso->exists;
    $titulo = $esNuevo ? 'Nuevo proceso' : 'Editar proceso';
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">{{ $titulo }}</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ $esNuevo ? route('procesos.guardar') : route('procesos.actualizar', $proceso) }}">
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            {{-- Un usuario con empresa no elige: el servidor le asigna la suya. --}}
            @if ($empresas->isNotEmpty())
                <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
            @else
                @error('id_empresa')
                    <x-alerta tipo="error">{{ $message }}</x-alerta>
                @enderror
            @endif

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $proceso->nombre)" maxlength="255" required autofocus />

            @unless ($esNuevo)
                <dl class="datos-fijos">
                    <div>
                        <dt>Empresa</dt>
                        <dd>{{ $proceso->empresa->nombre }}</dd>
                    </div>
                </dl>
            @endunless

            <div class="formulario-acciones">
                <a href="{{ route('procesos.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
