@extends('layouts.app')

@section('titulo', 'Mi contraseña')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Mi contraseña</h1>
            <p class="pagina-subtitulo">Para cambiarla, escribe primero la que usas ahora.</p>
        </div>
    </div>

    <div class="tarjeta tarjeta-formulario">
        <form method="POST" action="{{ route('mi-contrasena.actualizar') }}">
            @csrf
            @method('PUT')

            <x-campo name="contrasena_actual" label="Contraseña actual" type="password" autocomplete="current-password" required autofocus />
            <x-campo name="contrasena" label="Nueva contraseña" type="password" minlength="8" maxlength="255" autocomplete="new-password" required />
            <x-campo name="contrasena_confirmation" label="Repite la nueva contraseña" type="password" minlength="8" maxlength="255" autocomplete="new-password" required />

            <div class="formulario-acciones">
                <a href="{{ route('inicio') }}" class="btn btn-outline-primary">Cancelar</a>
                <x-boton>Cambiar contraseña</x-boton>
            </div>
        </form>
    </div>
@endsection
