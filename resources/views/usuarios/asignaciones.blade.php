{{-- Historial de empresas de un consultor y formulario para asignarle otra. --}}
@extends('layouts.app')

@php
    $activas = $asignaciones->where('activa', true);
@endphp

@section('titulo', 'Asignaciones')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Asignaciones de {{ $usuario->nombre }}</h1>
            <p class="pagina-subtitulo">
                @if ($activas->isEmpty())
                    Sin empresa asignada: por ahora no ve datos.
                @else
                    Trabaja con {{ $activas->pluck('empresa.nombre')->implode(', ') }}.
                @endif
            </p>
        </div>

        <a href="{{ route('usuarios.index') }}" class="btn btn-outline-primary">Volver a usuarios</a>
    </div>

    <div class="tarjeta tarjeta-formulario mb-3">
        <h2 class="tarjeta-titulo">Asignar a una empresa</h2>

        @if ($activas->isNotEmpty())
            <p class="texto-secundario">
                Un consultor trabaja con una sola empresa a la vez: al asignarlo, se cierra su asignación con
                {{ $activas->pluck('empresa.nombre')->implode(', ') }} (con fecha de fin de hoy).
                Lo que ya capturó se queda en esa empresa.
            </p>
        @endif

        @if ($empresas->isEmpty())
            <x-alerta tipo="advertencia">No hay otra empresa activa a la cual asignarlo.</x-alerta>
        @else
            <form method="POST" action="{{ route('usuarios.asignaciones.guardar', $usuario) }}">
                @csrf

                <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
                <x-campo name="fecha_inicio" label="Trabaja con ella desde" type="date" :value="old('fecha_inicio', today()->format('Y-m-d'))" :max="today()->format('Y-m-d')" required />

                <div class="formulario-acciones">
                    <x-boton>Asignar</x-boton>
                </div>
            </form>
        @endif
    </div>

    <div class="tarjeta">
        <h2 class="tarjeta-titulo">Historial</h2>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Empresa</th>
                        <th scope="col">Desde</th>
                        <th scope="col">Hasta</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($asignaciones as $asignacion)
                        <tr>
                            <td class="celda-titulo">{{ $asignacion->empresa->nombre }}</td>
                            <td class="celda-detalle">{{ $asignacion->fecha_inicio->format('d/m/Y') }}</td>
                            <td class="celda-detalle">{{ $asignacion->fecha_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-estado"><x-estado :activo="$asignacion->activa" texto-activo="Activa" texto-baja="Cerrada" /></td>
                            <td class="celda-acciones">
                                @if ($asignacion->activa)
                                    <div class="acciones">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cerrar-{{ $asignacion->id_usuario_empresa }}">Cerrar</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tabla-vacia">Todavía no tiene asignaciones.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($activas as $asignacion)
        <x-confirmar id="cerrar-{{ $asignacion->id_usuario_empresa }}" titulo="Cerrar asignación" :accion="route('asignaciones.cerrar', $asignacion)" boton="Cerrar asignación">
            ¿Quieres cerrar la asignación de {{ $usuario->nombre }} con {{ $asignacion->empresa->nombre }}? Quedará sin empresa y no verá datos hasta que lo asignes a otra. Lo que capturó se queda en la empresa.
        </x-confirmar>
    @endforeach
@endsection
