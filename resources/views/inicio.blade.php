{{-- Página provisional para probar el flujo; cambiará de layout con el layout principal. --}}
@extends('layouts.invitado')

@section('titulo', 'Inicio')

@section('contenido')
    <h1 class="acceso-titulo">{{ auth()->user()->nombre }}</h1>
    <p class="acceso-subtitulo">{{ auth()->user()->rol_app->etiqueta() }}</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-boton variante="outline-primary" class="btn-acceso w-100">Cerrar sesión</x-boton>
    </form>
@endsection
