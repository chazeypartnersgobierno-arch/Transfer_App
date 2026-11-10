@extends('layouts.app')

@section('titulo', 'Accesos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Accesos</h1>
            <p class="pagina-subtitulo">
                @if ($participante)
                    Solicitudes de {{ $participante->nombreCompleto() }}. <a href="{{ route('accesos.index') }}">Ver todas</a>
                @else
                    Solicitudes de acceso a los aplicativos.
                @endif
            </p>
        </div>

        <a href="{{ route('accesos.crear') }}" class="btn btn-primary">Nueva solicitud</a>
    </div>

    {{-- Cuántas hay en cada estado; cada uno filtra la lista. --}}
    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <a href="{{ request()->fullUrlWithQuery(['estado' => null, 'page' => null]) }}" @class(['nav-link', 'active' => $estado === null])>
                Todas ({{ $totales->sum() }})
            </a>
        </li>
        @foreach (\App\Enums\EstadoAcceso::cases() as $opcion)
            <li class="nav-item">
                <a href="{{ request()->fullUrlWithQuery(['estado' => $opcion->value, 'page' => null]) }}" @class(['nav-link', 'active' => $estado === $opcion])>
                    {{ $opcion->value }} ({{ $totales[$opcion->value] ?? 0 }})
                </a>
            </li>
        @endforeach
    </ul>

    <div class="tarjeta">
        <form method="GET" action="{{ route('accesos.index') }}" class="herramientas">
            @if ($participante)
                <input type="hidden" name="id_participante" value="{{ $participante->id_participante }}">
            @endif
            @if ($estado)
                <input type="hidden" name="estado" value="{{ $estado->value }}">
            @endif

            <label for="buscar" class="visually-hidden">Buscar participante</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar participante">

            @if ($empresas->isNotEmpty())
                <label for="filtro_empresa" class="visually-hidden">Filtrar por empresa</label>
                <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todas las empresas</option>
                    @foreach ($empresas as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            <label for="filtro_aplicativo" class="visually-hidden">Filtrar por aplicativo</label>
            <select id="filtro_aplicativo" name="id_aplicativo" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los aplicativos</option>
                @foreach ($aplicativos as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idAplicativo)>{{ $nombre }}</option>
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
        </form>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Participante</th>
                        <th scope="col">Aplicativo</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Solicitado</th>
                        <th scope="col">Otorgado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accesos as $acceso)
                        <tr>
                            <td class="celda-titulo">
                                {{ $acceso->participante->nombreCompleto() }}
                                @unless ($acceso->participante->estado)
                                    <span class="texto-secundario small">(de baja)</span>
                                @endunless
                                <div class="texto-secundario small">
                                    {{ $acceso->participante->grupo->bloque->nombre }} › {{ $acceso->participante->grupo->nombre }}
                                    @if (auth()->user()->esChazey())
                                        ({{ $acceso->participante->grupo->bloque->empresa->sigla }})
                                    @endif
                                </div>
                            </td>
                            <td class="celda-detalle" data-etiqueta="Aplicativo">{{ $acceso->aplicativo->nombre }}</td>
                            <td class="celda-detalle" data-etiqueta="Estado">{{ $acceso->estado->value }}</td>
                            <td class="celda-detalle" data-etiqueta="Solicitado">{{ $acceso->fecha_solicitud->format('d/m/Y') }}</td>
                            <td class="celda-detalle" data-etiqueta="Otorgado">{{ $acceso->fecha_otorgado?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-acciones">
                                <div class="acciones">
                                    <a href="{{ route('accesos.editar', $acceso) }}" class="btn btn-sm btn-outline-primary">{{ $acceso->sePuedeEditar() ? 'Editar' : 'Consultar' }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tabla-vacia">No hay solicitudes para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $accesos->total() }} {{ $accesos->total() === 1 ? 'solicitud' : 'solicitudes' }}</span>
            {{ $accesos->links() }}
        </div>
    </div>
@endsection
