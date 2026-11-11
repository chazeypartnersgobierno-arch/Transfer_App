@extends('layouts.app')

{{--
    Lista de seguimientos de UNA fase: cada fase es su propia pantalla (/seguimientos?id_fase=N)
    y se elige en el submenú de Seguimientos. Sin fase se muestra la primera.
--}}
@php($titulo = $fase ? "Seguimientos de {$fase->nombre}" : 'Seguimientos')
@php($conEmpresa = auth()->user()->esChazey())

@section('titulo', $titulo)

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">{{ $titulo }}</h1>
            <p class="pagina-subtitulo">
                @if ($fase?->tiene_sesiones)
                    Cada actividad de esta fase en cada grupo, con sus sesiones y asistencia.
                @elseif ($fase)
                    Cada actividad de esta fase en cada grupo, con su % de avance.
                @endif
            </p>
        </div>
    </div>

    {{--
        Equipo del proceso (responsable, documentador y líderes): uno por proceso, igual en todas
        las fases. Aquí solo se muestra, al filtrar por proceso; se cambia en Equipos de proceso.
    --}}
    @if ($equipo !== null)
        <div class="tarjeta equipo-resumen mb-3">
            <span class="equipo-resumen-titulo">Equipo de {{ $equipo['proceso']->nombre }}</span>
            <dl class="equipo-resumen-datos">
                @foreach (\App\Models\Seguimiento::CAMPOS_DEL_PROCESO as $campo => $etiqueta)
                    <div>
                        <dt>{{ $etiqueta }}</dt>
                        <dd>
                            @if (filled($equipo['valores'][$campo]))
                                {{ $equipo['valores'][$campo] }}
                            @else
                                <span class="equipo-sin-definir">Sin definir</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <a href="{{ route('equipos.editar', $equipo['proceso']) }}" class="btn btn-sm btn-outline-primary">{{ $equipo['editable'] ? 'Editar equipo' : 'Consultar equipo' }}</a>
        </div>
    @endif

    <div class="tarjeta">
        <form method="GET" action="{{ route('seguimientos.index') }}" class="herramientas">
            {{-- Los filtros se aplican dentro de la fase de esta pantalla. --}}
            @if ($fase)
                <input type="hidden" name="id_fase" value="{{ $fase->id_fase }}">
            @endif

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

            <label for="filtro_proceso" class="visually-hidden">Filtrar por proceso</label>
            <select id="filtro_proceso" name="id_proceso" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los procesos</option>
                @foreach ($procesos as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idProceso)>{{ $nombre }}</option>
                @endforeach
            </select>

            <label for="filtro_grupo" class="visually-hidden">Filtrar por grupo</label>
            <select id="filtro_grupo" name="id_grupo" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los grupos</option>
                @foreach ($grupos as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idGrupo)>{{ $nombre }}</option>
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
                        @if ($conEmpresa)
                            <th scope="col">Empresa</th>
                        @endif
                        <th scope="col">Inicio</th>
                        <th scope="col">Fin</th>
                        <th scope="col">Estado</th>
                        {{-- Fases con sesiones: cuántas tiene. Validación: su % actual (la captura más reciente). --}}
                        <th scope="col">{{ $fase?->tiene_sesiones ? 'Sesiones' : '% actual' }}</th>
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
                                @if (filled($seguimiento->actividad->macroproceso))
                                    <div class="texto-secundario small">{{ $seguimiento->actividad->macroproceso }}</div>
                                @endif
                            </td>
                            <td class="celda-detalle" data-etiqueta="Grupo"><a href="{{ route('grupos.fases', $seguimiento->grupo) }}" title="Ver las fases del grupo">{{ $seguimiento->grupo->proceso->nombre }} › {{ $seguimiento->grupo->nombre }}</a></td>
                            @if ($conEmpresa)
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $seguimiento->grupo->proceso->empresa->nombre }}</td>
                            @endif
                            <td class="celda-detalle" data-etiqueta="Inicio">{{ $seguimiento->fecha_inicio?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-detalle" data-etiqueta="Fin">{{ $seguimiento->fecha_fin?->format('d/m/Y') ?? '—' }}</td>
                            <td class="celda-estado">
                                <x-estado-seguimiento :seguimiento="$seguimiento" />
                                {{-- El bloqueo depende del grupo: cada uno avanza por su cuenta. --}}
                                @if ($bloqueado)
                                    <div class="texto-secundario small">
                                        <span class="fase-bloqueada-marca"><x-icono nombre="candado" :tamano="14" /></span>
                                        Fase bloqueada para este grupo
                                    </div>
                                @endif
                                @unless ($enTrabajo)
                                    <div class="texto-secundario small">Grupo o actividad de baja</div>
                                @endunless
                            </td>
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
                            <td colspan="{{ $conEmpresa ? 8 : 7 }}" class="tabla-vacia">No hay seguimientos para mostrar en esta fase.</td>
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
