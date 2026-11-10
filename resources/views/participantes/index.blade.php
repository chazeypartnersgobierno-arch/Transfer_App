@extends('layouts.app')

@section('titulo', 'Participantes')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Participantes</h1>
            <p class="pagina-subtitulo">Personas que reciben la transferencia.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar-participantes')
            <a href="{{ route('participantes.crear') }}" class="btn btn-primary">Nuevo participante</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('participantes.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar participante</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por nombre o correo">

            @if ($empresas->isNotEmpty())
                <label for="filtro_empresa" class="visually-hidden">Filtrar por empresa</label>
                <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todas las empresas</option>
                    @foreach ($empresas as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            <label for="filtro_bloque" class="visually-hidden">Filtrar por bloque</label>
            <select id="filtro_bloque" name="id_bloque" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los bloques</option>
                @foreach ($bloques as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idBloque)>{{ $nombre }}</option>
                @endforeach
            </select>

            <label for="filtro_grupo" class="visually-hidden">Filtrar por grupo</label>
            <select id="filtro_grupo" name="id_grupo" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los grupos</option>
                @foreach ($grupos as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idGrupo)>{{ $nombre }}</option>
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
                        <th scope="col">Correo</th>
                        <th scope="col">Rol</th>
                        <th scope="col">Grupo</th>
                        <th scope="col">Ingreso</th>
                        <th scope="col">Estado</th>
                        @can('administrar-participantes')
                            <th scope="col" class="text-end">Acciones</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($participantes as $participante)
                        <tr>
                            <td class="celda-titulo">{{ $participante->apellidos }}, {{ $participante->nombre }}</td>
                            <td class="celda-detalle" data-etiqueta="Correo">{{ $participante->correo }}</td>
                            <td class="celda-detalle" data-etiqueta="Rol">{{ $participante->rol }}</td>
                            <td class="celda-detalle" data-etiqueta="Grupo">
                                {{ $participante->grupo->bloque->nombre }} › {{ $participante->grupo->nombre }}
                                @if (auth()->user()->esChazey())
                                    <span class="texto-secundario">({{ $participante->grupo->bloque->empresa->sigla }})</span>
                                @endif
                            </td>
                            <td class="celda-detalle" data-etiqueta="Ingreso">{{ $participante->fecha_ingreso?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-estado"><x-estado :activo="$participante->estado" /></td>
                            @can('administrar-participantes')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @can('administrar-accesos')
                                            <a href="{{ route('accesos.index', ['id_participante' => $participante->id_participante]) }}" class="btn btn-sm btn-outline-primary">Accesos</a>
                                        @endcan
                                        @if ($participante->estado)
                                            <a href="{{ route('participantes.editar', $participante) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $participante->id_participante }}">Dar de baja</button>
                                        @else
                                            <form method="POST" action="{{ route('participantes.reactivar', $participante) }}" class="d-flex flex-fill">
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
                            <td colspan="7" class="tabla-vacia">
                                {{ $buscar !== '' ? 'Ningún participante coincide con tu búsqueda.' : 'No hay participantes para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $participantes->total() }} {{ $participantes->total() === 1 ? 'participante' : 'participantes' }}</span>
            {{ $participantes->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar-participantes')
        @foreach ($participantes->where('estado', true) as $participante)
            <x-confirmar id="baja-{{ $participante->id_participante }}" titulo="Dar de baja" :accion="route('participantes.baja', $participante)" boton="Dar de baja">
                ¿Quieres dar de baja a {{ $participante->nombreCompleto() }}? Su asistencia se conserva. Podrás reactivarlo después.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
