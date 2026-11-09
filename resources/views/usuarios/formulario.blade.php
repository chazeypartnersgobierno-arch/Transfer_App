{{--
    Se usa para crear y para editar.
    - La empresa solo se pide al crear y cuando un admin pasa a consultor.
      La empresa de un consultor se cambia en la pantalla Asignaciones.
    - Al editar, la contraseña es opcional: si se deja vacía no cambia.
--}}
@extends('layouts.app')

@php
    $esNuevo = ! $usuario->exists;
    $titulo = $esNuevo ? 'Nuevo usuario' : 'Editar usuario';
    $pideEmpresa = $esNuevo || $usuario->rol_app === \App\Enums\RolApp::Admin;
    $roles = collect(\App\Enums\RolApp::cases())->mapWithKeys(fn ($rol) => [$rol->value => $rol->etiqueta()]);
@endphp

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <h1 class="pagina-titulo">{{ $titulo }}</h1>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ $esNuevo ? route('usuarios.guardar') : route('usuarios.actualizar', $usuario) }}">
            @csrf
            @unless ($esNuevo)
                @method('PUT')
            @endunless

            <x-campo name="nombre" label="Nombre" :value="old('nombre', $usuario->nombre)" maxlength="255" required autofocus />
            <x-campo name="correo" label="Correo" type="email" :value="old('correo', $usuario->correo)" maxlength="255" required autocomplete="off" />
            <x-lista name="rol_app" label="Rol" :opciones="$roles" :value="old('rol_app', $usuario->rol_app?->value)" vacia="Elige un rol" required />

            @if ($pideEmpresa)
                <fieldset class="mb-3">
                    <legend class="form-label">Empresa (solo para consultores)</legend>

                    <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" />
                    <x-campo name="fecha_inicio" label="Desde" type="date" :value="old('fecha_inicio', today()->format('Y-m-d'))" :max="today()->format('Y-m-d')" />
                </fieldset>
            @else
                <p class="texto-secundario">
                    <a href="{{ route('usuarios.asignaciones', $usuario) }}">Cambiar su empresa</a>
                </p>
            @endif

            <fieldset class="mb-3">
                <legend class="form-label">{{ $esNuevo ? 'Contraseña' : 'Cambiar contraseña (opcional)' }}</legend>
                @unless ($esNuevo)
                    <p class="texto-secundario">Déjala vacía para no cambiarla.</p>
                @endunless

                <x-campo name="contrasena" label="Contraseña" type="password" minlength="8" maxlength="255" autocomplete="new-password" :required="$esNuevo" />
                <x-campo name="contrasena_confirmation" label="Repite la contraseña" type="password" minlength="8" maxlength="255" autocomplete="new-password" :required="$esNuevo" />
            </fieldset>

            <div class="formulario-acciones">
                <a href="{{ route('usuarios.index') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Guardar</x-boton>
            </div>
        </form>
    </div>
@endsection
