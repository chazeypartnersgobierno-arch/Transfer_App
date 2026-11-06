@extends('layouts.app')

@section('titulo', 'Fases')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Fases</h1>
            <p class="pagina-subtitulo">Etapas de la transferencia, en orden. Son las mismas para todas las empresas.</p>
        </div>
    </div>

    <div class="tarjeta">
        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Orden</th>
                        <th scope="col">Fase</th>
                        <th scope="col">Registra sesiones</th>
                        @can('administrar-fases')
                            <th scope="col" class="text-end">Acciones</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fases as $fase)
                        <tr>
                            <td class="celda-detalle" data-etiqueta="Orden">{{ $fase->orden }}</td>
                            <td class="celda-titulo">{{ $fase->nombre }}</td>
                            <td class="celda-detalle" data-etiqueta="Registra sesiones">{{ $fase->tiene_sesiones ? 'Sí' : 'No' }}</td>
                            @can('administrar-fases')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        <a href="{{ route('fases.editar', $fase) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                    </div>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
