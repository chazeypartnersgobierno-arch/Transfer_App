@extends('layouts.app')

@section('titulo', 'Inicio')

@php
    // El admin ve todas las empresas; el consultor, las de su asignación activa.
    $empresasDelConsultor = auth()->user()->esChazey()
        ? collect()
        : \App\Models\Empresa::whereKey(auth()->user()->idsEmpresasVisibles())->orderBy('nombre')->pluck('nombre');

    $accesos = [];

    // El seguimiento va primero: es el trabajo diario. La ruta también está protegida en el servidor.
    if (auth()->user()->can('dar-seguimiento')) {
        $accesos[] = ['seguimientos.index', 'seguimiento', 'Seguimientos'];
    }

    $accesos = [
        ...$accesos,
        ['empresas.index', 'empresa', 'Empresas'],
        ['fases.index', 'fase', 'Fases'],
        ['bloques.index', 'bloque', 'Bloques'],
        ['grupos.index', 'grupo', 'Grupos'],
        ['participantes.index', 'participante', 'Participantes'],
        ['aplicativos.index', 'aplicativo', 'Aplicativos'],
        ['actividades.index', 'actividad', 'Actividades'],
    ];

    // La carga solo la ve un admin; la ruta también está protegida en el servidor.
    if (auth()->user()->can('administrar')) {
        $accesos[] = ['usuarios.index', 'usuario', 'Usuarios'];
        $accesos[] = ['carga.index', 'carga', 'Carga desde Excel'];
    }
@endphp

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Hola, {{ auth()->user()->nombre }}</h1>
            <p class="pagina-subtitulo">
                @if ($empresasDelConsultor->isNotEmpty())
                    Trabajas con {{ $empresasDelConsultor->implode(', ') }}.
                @endif
                Elige por dónde quieres empezar.
            </p>
        </div>
    </div>

    @if (! auth()->user()->esChazey() && $empresasDelConsultor->isEmpty())
        <x-alerta tipo="advertencia">No tienes una empresa asignada. Pide al administrador que te asigne una.</x-alerta>
    @endif

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
