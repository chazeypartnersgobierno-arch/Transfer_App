@extends('layouts.app')

@section('titulo', 'Inicio')

@php
    $accesos = [
        ['empresas.index', 'empresa', 'Empresas'],
        ['fases.index', 'fase', 'Fases'],
        ['bloques.index', 'bloque', 'Bloques'],
        ['grupos.index', 'grupo', 'Grupos'],
        ['aplicativos.index', 'aplicativo', 'Aplicativos'],
        ['actividades.index', 'actividad', 'Actividades'],
    ];

    // La carga solo la ve un admin; la ruta también está protegida en el servidor.
    if (auth()->user()->can('administrar')) {
        $accesos[] = ['carga.index', 'carga', 'Carga desde Excel'];
    }
@endphp

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Hola, {{ auth()->user()->nombre }}</h1>
            <p class="pagina-subtitulo">Elige por dónde quieres empezar.</p>
        </div>
    </div>

    <div class="row g-3">
        @foreach ($accesos as [$ruta, $icono, $texto])
            <div class="col-sm-6 col-xl-4">
                <a href="{{ route($ruta) }}" class="tarjeta tarjeta-acceso">
                    <span class="tarjeta-icono"><x-icono :nombre="$icono" :tamano="22" /></span>
                    <span class="fw-semibold">{{ $texto }}</span>
                </a>
            </div>
        @endforeach
    </div>
@endsection
