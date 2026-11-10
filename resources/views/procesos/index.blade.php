@extends('layouts.app')

@section('titulo', 'Procesos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Procesos</h1>
            <p class="pagina-subtitulo">Cada proceso tiene sus grupos y sus actividades.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar')
            <a href="{{ route('procesos.crear') }}" class="btn btn-primary">Nuevo proceso</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('procesos.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar proceso</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por nombre">

            @if ($empresas->isNotEmpty())
                <label for="filtro_empresa" class="visually-hidden">Filtrar por empresa</label>
                <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todas las empresas</option>
                    @foreach ($empresas as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            <x-boton variante="outline-primary">Buscar</x-boton>

            <div class="form-check ms-sm-auto">
                <input id="ver_bajas" type="checkbox" name="ver_bajas" value="1" class="form-check-input" @checked($verBajas) data-enviar-al-cambiar>
                <label for="ver_bajas" class="form-check-label">Ver dados de baja</label>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        @if (auth()->user()->esChazey())
                            <th scope="col">Empresa</th>
                        @endif
                        <th scope="col">Estado</th>
                        @can('administrar')
                            <th scope="col" class="text-end">Acciones</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($procesos as $proceso)
                        <tr>
                            <td class="celda-titulo">{{ $proceso->nombre }}</td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle">{{ $proceso->empresa->nombre }}</td>
                            @endif
                            <td class="celda-estado"><x-estado :activo="$proceso->estado" /></td>
                            @can('administrar')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($proceso->estado)
                                            <a href="{{ route('procesos.editar', $proceso) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $proceso->id_proceso }}">Dar de baja</button>
                                        @else
                                            <form method="POST" action="{{ route('procesos.reactivar', $proceso) }}" class="d-flex flex-fill">
                                                @csrf
                                                @method('PATCH')
                                                <x-boton variante="outline-primary" class="btn-sm flex-fill">Reactivar</x-boton>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="tabla-vacia">
                                {{ $buscar !== '' ? 'Ningún proceso coincide con tu búsqueda.' : 'No hay procesos para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $procesos->total() }} {{ $procesos->total() === 1 ? 'proceso' : 'procesos' }}</span>
            {{ $procesos->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar')
        @foreach ($procesos->where('estado', true) as $proceso)
            <x-confirmar id="baja-{{ $proceso->id_proceso }}" titulo="Dar de baja" :accion="route('procesos.baja', $proceso)" boton="Dar de baja">
                ¿Quieres dar de baja el proceso {{ $proceso->nombre }}? Podrás reactivarlo después.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
