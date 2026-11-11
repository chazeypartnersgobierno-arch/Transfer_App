{{--
    Equipos de proceso: responsable, documentador y líderes de cada proceso (ver EquipoDeProcesoController).
    Se ven todos de una vez para encontrar los que faltan por definir.
--}}
@extends('layouts.app')

@php($conEmpresa = auth()->user()->esChazey())

@section('titulo', 'Equipos de proceso')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Equipos de proceso</h1>
            <p class="pagina-subtitulo">Responsable, documentador y líderes: los mismos en todos los seguimientos de un proceso.</p>
        </div>
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('equipos.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar proceso</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por proceso">

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
                <label for="ver_bajas" class="form-check-label">Ver procesos de baja</label>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Proceso</th>
                        @if ($conEmpresa)
                            <th scope="col">Empresa</th>
                        @endif
                        @foreach (\App\Models\Seguimiento::CAMPOS_DEL_PROCESO as $etiqueta)
                            <th scope="col">{{ $etiqueta }}</th>
                        @endforeach
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($procesos as $proceso)
                        @php($equipo = $equipos[$proceso->id_proceso] ?? null)
                        @php($editable = $proceso->estado && $equipo !== null)
                        <tr>
                            <td class="celda-titulo">
                                {{ $proceso->nombre }}
                                @unless ($proceso->estado)
                                    <div class="texto-secundario small">Proceso de baja</div>
                                @endunless
                            </td>
                            @if ($conEmpresa)
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $proceso->empresa->nombre }}</td>
                            @endif
                            @if ($equipo === null)
                                {{-- El equipo se guarda en los seguimientos: sin ellos todavía no hay dónde. --}}
                                <td class="celda-detalle texto-secundario" colspan="{{ count(\App\Models\Seguimiento::CAMPOS_DEL_PROCESO) }}">
                                    Todavía no tiene seguimientos.
                                </td>
                            @else
                                @foreach (\App\Models\Seguimiento::CAMPOS_DEL_PROCESO as $campo => $etiqueta)
                                    <td class="celda-detalle" data-etiqueta="{{ $etiqueta }}">
                                        @if (filled($equipo['valores'][$campo]))
                                            {{ $equipo['valores'][$campo] }}
                                        @else
                                            <span class="equipo-sin-definir">Sin definir</span>
                                        @endif
                                    </td>
                                @endforeach
                            @endif
                            <td class="celda-acciones">
                                <div class="acciones">
                                    <a href="{{ route('equipos.editar', $proceso) }}" class="btn btn-sm btn-outline-primary">{{ $editable ? 'Editar' : 'Consultar' }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($conEmpresa ? 3 : 2) + count(\App\Models\Seguimiento::CAMPOS_DEL_PROCESO) }}" class="tabla-vacia">
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
@endsection
