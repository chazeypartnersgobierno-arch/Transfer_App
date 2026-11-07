@extends('layouts.app')

@section('titulo', 'Bloques')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Bloques</h1>
            <p class="pagina-subtitulo">Cada grupo pertenece a un bloque, y las actividades cambian según el bloque.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar')
            <a href="{{ route('bloques.crear') }}" class="btn btn-primary">Nuevo bloque</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('bloques.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar bloque</label>
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
                    @forelse ($bloques as $bloque)
                        <tr>
                            <td class="celda-titulo">{{ $bloque->nombre }}</td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle">{{ $bloque->empresa->nombre }}</td>
                            @endif
                            <td class="celda-estado"><x-estado :activo="$bloque->estado" /></td>
                            @can('administrar')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($bloque->estado)
                                            <a href="{{ route('bloques.editar', $bloque) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $bloque->id_bloque }}">Dar de baja</button>
                                        @else
                                            <form method="POST" action="{{ route('bloques.reactivar', $bloque) }}" class="d-flex flex-fill">
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
                                {{ $buscar !== '' ? 'Ningún bloque coincide con tu búsqueda.' : 'No hay bloques para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $bloques->total() }} {{ $bloques->total() === 1 ? 'bloque' : 'bloques' }}</span>
            {{ $bloques->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar')
        @foreach ($bloques->where('estado', true) as $bloque)
            <x-confirmar id="baja-{{ $bloque->id_bloque }}" titulo="Dar de baja" :accion="route('bloques.baja', $bloque)" boton="Dar de baja">
                ¿Quieres dar de baja el bloque {{ $bloque->nombre }}? No se borra su información y podrás reactivarlo después.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
