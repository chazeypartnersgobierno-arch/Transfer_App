{{--
    Fases de un grupo en pestañas (ver FasesDelGrupoController).
    Completas: ✔. Actual: resaltada. Bloqueadas: deshabilitadas con candado.
--}}
@extends('layouts.app')

@section('titulo', "Fases de {$grupo->nombre}")

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Fases del grupo {{ $grupo->nombre }}</h1>
            <p class="pagina-subtitulo">Bloque {{ $grupo->bloque->nombre }} · {{ $grupo->bloque->empresa->nombre }}</p>
        </div>
        <a href="{{ route('seguimientos.index', ['id_grupo' => $grupo->id_grupo, 'id_bloque' => $grupo->id_bloque]) }}" class="btn btn-outline-primary">Ver en la lista de seguimientos</a>
    </div>

    @unless ($grupo->estado)
        <x-alerta tipo="advertencia">El grupo está dado de baja: sus seguimientos no aparecen aquí ni cuentan en el avance.</x-alerta>
    @endunless

    <ul class="nav nav-tabs pestanas-fases">
        @foreach ($pestanas as $pestana)
            <li class="nav-item">
                @if ($pestana->habilitada)
                    <a href="{{ route('grupos.fases', ['grupo' => $grupo, 'fase' => $pestana->fase->id_fase]) }}"
                        @class(['nav-link', 'active' => $pestana === $elegida, 'fase-actual' => $pestana->actual])
                        @if ($pestana === $elegida) aria-current="page" @endif>
                        @if ($pestana->completa)
                            <x-icono nombre="completa" :tamano="16" /><span class="visually-hidden">Completa:</span>
                        @endif
                        {{ $pestana->fase->nombre }}
                        @if ($pestana->actual)
                            <span class="badge fase-actual-etiqueta">Actual</span>
                        @endif
                    </a>
                @else
                    {{-- Bloqueada: no se puede abrir. --}}
                    <span class="nav-link disabled" aria-disabled="true" title="Se habilita al concluir la fase anterior">
                        <x-icono nombre="candado" :tamano="16" /><span class="visually-hidden">Bloqueada:</span>
                        {{ $pestana->fase->nombre }}
                    </span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="tarjeta pestanas-contenido">
        @if ($bloqueo)
            <x-fase-bloqueada :mensaje="$bloqueo" class="mb-0" />
        @else
            <p class="mb-3">
                <strong>{{ $elegida->fase->nombre }}:</strong>
                {{ $elegida->avance['terminadas'] }} de {{ $elegida->avance['total'] }} {{ $elegida->avance['total'] === 1 ? 'actividad terminada' : 'actividades terminadas' }}.
            </p>

            <div class="table-responsive">
                <table class="table tabla-apilada">
                    <thead>
                        <tr>
                            <th scope="col">Actividad</th>
                            <th scope="col">Inicio</th>
                            <th scope="col">Fin</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($seguimientos as $seguimiento)
                            <tr>
                                <td class="celda-titulo">
                                    {{ $seguimiento->actividad->nombre }}
                                    @if ($seguimiento->actividad->rutaDeProceso() !== '')
                                        <div class="texto-secundario small">{{ $seguimiento->actividad->rutaDeProceso() }}</div>
                                    @endif
                                </td>
                                <td class="celda-detalle" data-etiqueta="Inicio">{{ $seguimiento->fecha_inicio?->format('d/m/Y') ?? '—' }}</td>
                                <td class="celda-detalle" data-etiqueta="Fin">{{ $seguimiento->fecha_fin?->format('d/m/Y') ?? '—' }}</td>
                                <td class="celda-estado"><x-estado-seguimiento :seguimiento="$seguimiento" /></td>
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        <a href="{{ route('seguimientos.editar', $seguimiento) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="tabla-vacia">Esta fase no tiene actividades para este grupo.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
