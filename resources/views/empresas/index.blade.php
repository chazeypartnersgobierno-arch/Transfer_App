@extends('layouts.app')

@section('titulo', 'Empresas')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Empresas</h1>
            <p class="pagina-subtitulo">Empresas que usan la app.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar-empresas')
            <a href="{{ route('empresas.crear') }}" class="btn btn-primary">Nueva empresa</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('empresas.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar empresa</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por nombre o sigla">
            <x-boton variante="outline-primary">Buscar</x-boton>

            <div class="form-check ms-sm-auto">
                <input id="ver_bajas" type="checkbox" name="ver_bajas" value="1" class="form-check-input" @checked($verBajas) data-enviar-al-cambiar>
                <label for="ver_bajas" class="form-check-label">Ver dadas de baja</label>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col">Sigla</th>
                        <th scope="col">Estado</th>
                        @can('administrar-empresas')
                            <th scope="col" class="text-end">Acciones</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($empresas as $empresa)
                        <tr>
                            <td class="celda-titulo">{{ $empresa->nombre }}</td>
                            <td class="celda-detalle">{{ $empresa->sigla }}</td>
                            <td class="celda-estado"><x-estado :activo="$empresa->estado" texto-activo="Activa" /></td>
                            @can('administrar-empresas')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($empresa->estado)
                                            <a href="{{ route('empresas.editar', $empresa) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $empresa->id_empresa }}">Dar de baja</button>
                                        @else
                                            <form method="POST" action="{{ route('empresas.reactivar', $empresa) }}" class="d-flex flex-fill">
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
                                {{ $buscar !== '' ? 'Ninguna empresa coincide con tu búsqueda.' : 'Todavía no hay empresas registradas.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $empresas->total() }} {{ $empresas->total() === 1 ? 'empresa' : 'empresas' }}</span>
            {{ $empresas->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar-empresas')
        @foreach ($empresas->where('estado', true) as $empresa)
            <x-confirmar id="baja-{{ $empresa->id_empresa }}" titulo="Dar de baja" :accion="route('empresas.baja', $empresa)" boton="Dar de baja">
                ¿Quieres dar de baja la empresa {{ $empresa->nombre }}? No se borra su información y podrás reactivarla después.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
