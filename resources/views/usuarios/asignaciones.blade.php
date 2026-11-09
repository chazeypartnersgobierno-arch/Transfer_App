{{-- Empresa de un consultor: cambio de empresa e historial. Las reglas están en App\Services\AsignadorDeEmpresas. --}}
@extends('layouts.app')

@php
    $activas = $asignaciones->where('activa', true);
@endphp

@section('titulo', "Empresa de {$usuario->nombre}")

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Empresa de {{ $usuario->nombre }}</h1>
            <p class="pagina-subtitulo">
                {{ $activas->isEmpty() ? 'Sin empresa asignada.' : 'Trabaja con '.$activas->pluck('empresa.nombre')->implode(', ').'.' }}
            </p>
        </div>

        <a href="{{ route('usuarios.index') }}" class="btn btn-outline-primary">Volver a usuarios</a>
    </div>

    @if ($empresas->isEmpty())
        <x-alerta tipo="advertencia">
            {{ $activas->isEmpty() ? 'Por ahora no hay empresas activas para asignarlo.' : 'Por ahora no hay otra empresa a la cual cambiarlo.' }}
        </x-alerta>
    @else
        <div class="tarjeta tarjeta-formulario mb-3">
            <h2 class="tarjeta-titulo">{{ $activas->isEmpty() ? 'Asignar empresa' : 'Cambiar de empresa' }}</h2>

            @if ($activas->isNotEmpty())
                <p class="texto-secundario">Al cambiarlo de empresa, su asignación actual se cierra automáticamente. Lo que ya capturó se conserva.</p>
            @endif

            <form method="POST" action="{{ route('usuarios.asignaciones.guardar', $usuario) }}">
                @csrf

                <x-lista name="id_empresa" label="Empresa" :opciones="$empresas" :value="old('id_empresa')" vacia="Elige una empresa" required />
                <x-campo name="fecha_inicio" label="Desde" type="date" :value="old('fecha_inicio', today()->format('Y-m-d'))" :max="today()->format('Y-m-d')" required />

                <div class="formulario-acciones">
                    <x-boton>{{ $activas->isEmpty() ? 'Asignar' : 'Cambiar' }}</x-boton>
                </div>
            </form>
        </div>
    @endif

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
                            <td class="celda-estado"><x-estado :activo="$asignacion->activa" texto-activo="Actual" texto-baja="Terminada" /></td>
                            <td class="celda-acciones">
                                @if ($asignacion->activa)
                                    <div class="acciones">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#quitar-{{ $asignacion->id_usuario_empresa }}">Quitar empresa</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="tabla-vacia">Todavía no ha tenido empresa.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($activas as $asignacion)
        <x-confirmar id="quitar-{{ $asignacion->id_usuario_empresa }}" titulo="Quitar empresa" :accion="route('asignaciones.cerrar', $asignacion)" boton="Quitar empresa">
            ¿Quieres quitar a {{ $usuario->nombre }} de {{ $asignacion->empresa->nombre }}? Se quedará sin empresa.
        </x-confirmar>
    @endforeach
@endsection
