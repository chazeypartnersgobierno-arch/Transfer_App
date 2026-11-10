@extends('layouts.app')

@section('titulo', 'Grupos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Grupos</h1>
            <p class="pagina-subtitulo">Grupos de participantes de cada proceso.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar')
            <a href="{{ route('grupos.crear') }}" class="btn btn-primary">Nuevo grupo</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('grupos.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar grupo</label>
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

            <label for="filtro_proceso" class="visually-hidden">Filtrar por proceso</label>
            <select id="filtro_proceso" name="id_proceso" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los procesos</option>
                @foreach ($procesos as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idProceso)>{{ $nombre }}</option>
                @endforeach
            </select>

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
                        <th scope="col">Proceso</th>
                        @if (auth()->user()->esChazey())
                            <th scope="col">Empresa</th>
                        @endif
                        <th scope="col">Seguimientos</th>
                        <th scope="col">Estado</th>
                        @can('administrar')
                            <th scope="col" class="text-end">Acciones</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($grupos as $grupo)
                        <tr>
                            <td class="celda-titulo">{{ $grupo->nombre }}</td>
                            <td class="celda-detalle" data-etiqueta="Proceso">{{ $grupo->proceso->nombre }}</td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $grupo->proceso->empresa->nombre }}</td>
                            @endif
                            <td class="celda-detalle" data-etiqueta="Seguimientos">{{ $grupo->seguimientos_count }}</td>
                            <td class="celda-estado"><x-estado :activo="$grupo->estado" /></td>
                            @can('administrar')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($grupo->estado)
                                            <a href="{{ route('grupos.editar', $grupo) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $grupo->id_grupo }}">Dar de baja</button>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reactivar-{{ $grupo->id_grupo }}">Reactivar</button>
                                        @endif
                                    </div>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tabla-vacia">
                                {{ $buscar !== '' ? 'Ningún grupo coincide con tu búsqueda.' : 'No hay grupos para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $grupos->total() }} {{ $grupos->total() === 1 ? 'grupo' : 'grupos' }}</span>
            {{ $grupos->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar')
        @foreach ($grupos->where('estado', true) as $grupo)
            <x-confirmar id="baja-{{ $grupo->id_grupo }}" titulo="Dar de baja" :accion="route('grupos.baja', $grupo)" boton="Dar de baja">
                ¿Quieres dar de baja el grupo {{ $grupo->nombre }}?
                {{ \App\Models\Seguimiento::avisoDeBaja($grupo->seguimientos_count) }} Podrás reactivarlo después.
            </x-confirmar>
        @endforeach

        @foreach ($grupos->where('estado', false) as $grupo)
            <x-confirmar id="reactivar-{{ $grupo->id_grupo }}" titulo="Reactivar" :accion="route('grupos.reactivar', $grupo)" boton="Reactivar">
                ¿Quieres reactivar el grupo {{ $grupo->nombre }}?
                @if ($faltantes[$grupo->id_grupo] > 0)
                    {{ \App\Models\Seguimiento::seGeneraran($faltantes[$grupo->id_grupo]) }}.
                @else
                    No hace falta generar seguimientos nuevos.
                @endif
                Los existentes no cambian.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
