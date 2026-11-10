{{-- Se usa para crear y para editar. Al editar, el grupo solo puede ser otro de su misma empresa. --}}
@extends('layouts.app')

@php
    $esNuevo = ! $participante->exists;
    $titulo = $esNuevo ? 'Nuevo participante' : 'Editar participante';
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">{{ $titulo }}</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ $esNuevo ? route('participantes.guardar') : route('participantes.actualizar', $participante) }}">
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            <div class="row">
                <div class="col-sm-6">
                    <x-campo name="nombre" label="Nombre" :value="old('nombre', $participante->nombre)" maxlength="255" required autofocus />
                </div>
                <div class="col-sm-6">
                    <x-campo name="apellidos" label="Apellidos" :value="old('apellidos', $participante->apellidos)" maxlength="255" required />
                </div>
            </div>

            <x-campo name="correo" label="Correo" type="email" :value="old('correo', $participante->correo)" maxlength="255" required />

            <div class="row">
                <div class="col-sm-6">
                    <x-campo name="rol" label="Rol" :value="old('rol', $participante->rol)" maxlength="50" required />
                </div>
                <div class="col-sm-6">
                    <x-campo name="fecha_ingreso" label="Fecha de ingreso (opcional)" type="date" :value="old('fecha_ingreso', $participante->fecha_ingreso?->format('Y-m-d'))" />
                </div>
            </div>

            <x-lista name="id_grupo" label="Grupo" :opciones="$grupos" :value="old('id_grupo', $participante->id_grupo)" vacia="Elige un grupo" required />

            <div class="mb-3">
                <label for="comentarios" class="form-label">Comentarios (opcional)</label>
                <textarea id="comentarios" name="comentarios" rows="3" maxlength="10000"
                    @error('comentarios') aria-invalid="true" aria-describedby="comentarios-error" @enderror
                    @class(['form-control', 'is-invalid' => $errors->has('comentarios')])>{{ old('comentarios', $participante->comentarios) }}</textarea>
                @error('comentarios')
                    <div id="comentarios-error" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="formulario-acciones">
                <a href="{{ route('participantes.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
