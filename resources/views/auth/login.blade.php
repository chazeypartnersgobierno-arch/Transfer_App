@extends('layouts.invitado')

@section('titulo', 'Iniciar sesión')

@section('contenido')
    <h1 class="acceso-titulo">Iniciar sesión</h1>
    <p class="acceso-subtitulo">Ingresa con tu correo y contraseña.</p>

    {{-- Errores generales del acceso. Los errores de cada campo los muestra x-campo. --}}
    @error('acceso')
        <x-alerta tipo="error">{{ $message }}</x-alerta>
    @enderror

    {{-- data-enviando: texto del botón mientras se envía (lo usa public/js/app.js). --}}
    <form method="POST" action="{{ route('login') }}" data-enviando="Entrando…">
        {{-- @csrf agrega un código secreto que protege el formulario contra envíos falsos. --}}
        @csrf

        <x-campo name="correo" label="Correo" type="email" :value="old('correo')" placeholder="nombre@empresa.com" autocomplete="username" required autofocus />
        <x-campo name="contrasena" label="Contraseña" type="password" autocomplete="current-password" required />

        <x-boton class="btn-acceso w-100 mt-2">Entrar</x-boton>
    </form>
@endsection
