@extends('layouts.app')

@section('titulo', 'Seguimientos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Seguimientos</h1>
            <p class="pagina-subtitulo">Cada actividad en cada grupo y fase.</p>
        </div>

        {{-- El equipo (responsable, documentador y líderes) es uno por bloque: se cambia desde aquí. --}}
        @if ($idBloque !== null)
            <a href="{{ route('seguimientos.equipo', $idBloque) }}" class="btn btn-outline-primary">Equipo del bloque</a>
        @endif
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('seguimientos.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar actividad</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por actividad">

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

            <label for="filtro_fase" class="visually-hidden">Filtrar por fase</label>
            <select id="filtro_fase" name="id_fase" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todas las fases</option>
                @foreach ($fases as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idFase)>{{ $nombre }}</option>
                @endforeach
            </select>

            <label for="filtro_estado" class="visually-hidden">Filtrar por estado</label>
            <select id="filtro_estado" name="estado" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los estados</option>
                @foreach ($estados as $valor => $texto)
                    <option value="{{ $valor }}" @selected($valor === $estado)>{{ $texto }}</option>
                @endforeach
            </select>

            <x-boton variante="outline-primary">Buscar</x-boton>

            <div class="form-check ms-sm-auto">
                <input id="ver_bajas" type="checkbox" name="ver_bajas" value="1" class="form-check-input" @checked($verBajas) data-enviar-al-cambiar>
                <label for="ver_bajas" class="form-check-label">Ver de grupos o actividades de baja</label>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table tabla-apilada">
                <thead>
                    <tr>
                        <th scope="col">Actividad</th>
                        <th scope="col">Grupo</th>
                        @if (auth()->user()->esChazey())
                            <th scope="col">Empresa</th>
                        @endif
                        <th scope="col">Fase</th>
                        <th scope="col">Inicio</th>
                        <th scope="col">Fin</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Sesiones / % actual</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($seguimientos as $seguimiento)
                        @php($enTrabajo = $seguimiento->estaEnTrabajo())
                        @php($bloqueado = $bloqueados->has($seguimiento->id_seguimiento))
                        <tr>
                            <td class="celda-titulo">
                                {{ $seguimiento->actividad->nombre }}
                                @if ($seguimiento->actividad->rutaDeProceso() !== '')
                                    <div class="texto-secundario small">{{ $seguimiento->actividad->rutaDeProceso() }}</div>
                                @endif
                            </td>
                            <td class="celda-detalle" data-etiqueta="Grupo"><a href="{{ route('grupos.fases', $seguimiento->grupo) }}" title="Ver las fases del grupo">{{ $seguimiento->grupo->bloque->nombre }} › {{ $seguimiento->grupo->nombre }}</a></td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $seguimiento->grupo->bloque->empresa->nombre }}</td>
                            @endif
                            <td class="celda-detalle" data-etiqueta="Fase">
                                {{ $seguimiento->fase->nombre }}
                                @if ($bloqueado)
                                    <span class="fase-bloqueada-marca" title="Fase bloqueada: falta concluir la fase anterior"><x-icono nombre="candado" :tamano="14" /><span class="visually-hidden">Bloqueada</span></span>
                                @endif
                            </td>
                            <td class="celda-detalle" data-etiqueta="Inicio">{{ $seguimiento->fecha_inicio?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-detalle" data-etiqueta="Fin">{{ $seguimiento->fecha_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-estado">
                                <x-estado-seguimiento :seguimiento="$seguimiento" />
                                @unless ($enTrabajo)
                                    <div class="texto-secundario small">Grupo o actividad de baja</div>
                                @endunless
                            </td>
                            {{-- Fases con sesiones: cuántas tiene. Validación: su % actual (la captura más reciente). --}}
                            @if ($seguimiento->fase->tiene_sesiones)
                                <td class="celda-detalle" data-etiqueta="Sesiones">{{ $seguimiento->sesiones_count }}</td>
                            @else
                                <td class="celda-detalle" data-etiqueta="% actual">{{ $seguimiento->avanceActual ? $seguimiento->avanceActual->porcentaje_real.' %' : '—' }}</td>
                            @endif
                            <td class="celda-acciones">
                                <div class="acciones">
                                    <a href="{{ route('seguimientos.editar', $seguimiento) }}" class="btn btn-sm btn-outline-primary">{{ $enTrabajo && ! $bloqueado ? 'Editar' : 'Consultar' }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="tabla-vacia">No hay seguimientos para mostrar.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $seguimientos->total() }} {{ $seguimientos->total() === 1 ? 'seguimiento' : 'seguimientos' }}</span>
            {{ $seguimientos->links() }}
        </div>
    </div>
@endsection
