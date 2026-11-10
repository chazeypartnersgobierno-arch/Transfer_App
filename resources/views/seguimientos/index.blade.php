@extends('layouts.app')

@section('titulo', 'Seguimientos')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Seguimientos</h1>
            <p class="pagina-subtitulo">Cada actividad en cada grupo y fase.</p>
        </div>
    </div>

    {{--
        Equipo del proceso (responsable, documentador y líderes): uno por proceso, por eso se
        muestra una sola vez aquí, al filtrar por proceso. Los campos empiezan bloqueados y se
        abren con "Editar" (public/js/app.js); sin JavaScript quedan abiertos y se puede guardar.
    --}}
    @if ($equipo !== null)
        @php($conErrores = $errors->hasAny(array_keys(\App\Models\Seguimiento::CAMPOS_DEL_PROCESO)))
        <div class="tarjeta equipo-proceso mb-3" id="equipo">
            @if (! $equipo['proceso']->estado)
                <x-alerta tipo="advertencia">El proceso está dado de baja: su equipo solo se puede consultar.</x-alerta>
            @elseif ($equipo['total'] === 0)
                <x-alerta tipo="advertencia">Este proceso todavía no tiene seguimientos.</x-alerta>
            @elseif ($equipo['distintos']->isNotEmpty())
                <x-alerta tipo="advertencia">
                    Hay valores distintos en: {{ $equipo['distintos']->implode(', ') }}. Al guardar, todos quedan iguales.
                </x-alerta>
            @endif

            <form method="POST" action="{{ route('seguimientos.equipo.actualizar', $equipo['proceso']) }}"
                @if ($equipo['editable']) data-equipo-proceso @if ($conErrores) data-abierto @endif @endif>
                @csrf
                @method('PUT')

                <div class="equipo-proceso-encabezado">
                    <h2 class="tarjeta-titulo mb-0">Equipo del proceso {{ $equipo['proceso']->nombre }}</h2>
                    @if ($equipo['editable'])
                        {{-- Empieza oculto: el JavaScript lo muestra. --}}
                        <button type="button" class="btn btn-sm btn-outline-primary" data-equipo-editar hidden>Editar</button>
                    @endif
                </div>

                <fieldset @disabled(! $equipo['editable'])>
                    <div class="row">
                        @foreach (\App\Models\Seguimiento::CAMPOS_DEL_PROCESO as $campo => $etiqueta)
                            <div class="col-sm-6 col-xl-3">
                                {{-- data-original: el valor guardado, para que "Cancelar" lo regrese. --}}
                                <x-campo :name="$campo" :label="$etiqueta" :value="old($campo, $equipo['valores'][$campo])" maxlength="255"
                                    data-original="{{ $equipo['valores'][$campo] }}" />
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                @if ($equipo['editable'])
                    <div class="equipo-proceso-acciones" data-equipo-acciones>
                        <span class="texto-secundario small">
                            Se {{ $equipo['total'] === 1 ? 'actualizará 1 seguimiento' : "actualizarán {$equipo['total']} seguimientos" }}.
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-equipo-cancelar hidden>Cancelar</button>
                        <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                    </div>
                @endif
            </form>
        </div>
    @endif

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
                                @if (filled($seguimiento->actividad->macroproceso))
                                    <div class="texto-secundario small">{{ $seguimiento->actividad->macroproceso }}</div>
                                @endif
                            </td>
                            <td class="celda-detalle" data-etiqueta="Grupo"><a href="{{ route('grupos.fases', $seguimiento->grupo) }}" title="Ver las fases del grupo">{{ $seguimiento->grupo->proceso->nombre }} › {{ $seguimiento->grupo->nombre }}</a></td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $seguimiento->grupo->proceso->empresa->nombre }}</td>
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
