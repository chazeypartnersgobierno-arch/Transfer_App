@extends('layouts.app')

@section('titulo', 'Aplicativos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Aplicativos</h1>
            <p class="pagina-subtitulo">Sistemas a los que los participantes solicitan acceso.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar')
            <a href="{{ route('aplicativos.crear') }}" class="btn btn-primary">Nuevo aplicativo</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('aplicativos.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar aplicativo</label>
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
                    @forelse ($aplicativos as $aplicativo)
                        <tr>
                            <td class="celda-titulo">{{ $aplicativo->nombre }}</td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle">{{ $aplicativo->empresa->nombre }}</td>
                            @endif
                            <td class="celda-estado"><x-estado :activo="$aplicativo->estado" /></td>
                            @can('administrar')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($aplicativo->estado)
                                            <a href="{{ route('aplicativos.editar', $aplicativo) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $aplicativo->id_aplicativo }}">Dar de baja</button>
                                        @else
                                            <form method="POST" action="{{ route('aplicativos.reactivar', $aplicativo) }}" class="d-flex flex-fill">
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
                                {{ $buscar !== '' ? 'Ningún aplicativo coincide con tu búsqueda.' : 'No hay aplicativos para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $aplicativos->total() }} {{ $aplicativos->total() === 1 ? 'aplicativo' : 'aplicativos' }}</span>
            {{ $aplicativos->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar')
        @foreach ($aplicativos->where('estado', true) as $aplicativo)
            <x-confirmar id="baja-{{ $aplicativo->id_aplicativo }}" titulo="Dar de baja" :accion="route('aplicativos.baja', $aplicativo)" boton="Dar de baja">
                ¿Quieres dar de baja el aplicativo {{ $aplicativo->nombre }}? No se borra su información y podrás reactivarlo después.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
