@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Hola, {{ auth()->user()->nombre }}</h1>
            <p class="pagina-subtitulo">Elige por dónde quieres empezar.</p>
        </div>
    </div>

    <div class="row g-3">
        @foreach ([['empresas.index', 'empresa', 'Empresas'], ['fases.index', 'fase', 'Fases'], ['bloques.index', 'bloque', 'Bloques'], ['aplicativos.index', 'aplicativo', 'Aplicativos']] as [$ruta, $icono, $texto])
            <div class="col-sm-6 col-xl-4">
                <a href="{{ route($ruta) }}" class="tarjeta tarjeta-acceso">
                    <span class="tarjeta-icono"><x-icono :nombre="$icono" :tamano="22" /></span>
                    <span class="fw-semibold">{{ $texto }}</span>
                </a>
            </div>
        @endforeach
    </div>
@endsection
