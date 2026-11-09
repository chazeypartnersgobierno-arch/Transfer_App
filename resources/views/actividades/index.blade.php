@extends('layouts.app')

@section('titulo', 'Actividades')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Actividades</h1>
            <p class="pagina-subtitulo">Lo que se transfiere. Onboarding pasa por una fase; Transición, por Transferencia, Estabilización y Validación.</p>
        </div>

        {{-- Esto solo oculta el botón; la ruta también está protegida en el servidor. --}}
        @can('administrar')
            <a href="{{ route('actividades.crear') }}" class="btn btn-primary">Nueva actividad</a>
        @endcan
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('actividades.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar actividad</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Nombre o proceso">

            @if ($empresas->isNotEmpty())
                <label for="filtro_empresa" class="visually-hidden">Filtrar por empresa</label>
                <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                    <option value="">Todas las empresas</option>
                    @foreach ($empresas as $id => $nombre)
                        <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif

            <label for="filtro_tipo" class="visually-hidden">Filtrar por tipo</label>
            <select id="filtro_tipo" name="tipo" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los tipos</option>
                @foreach (\App\Enums\TipoActividad::cases() as $opcion)
                    <option value="{{ $opcion->value }}" @selected($opcion === $tipo)>{{ $opcion->value }}</option>
                @endforeach
            </select>

            <label for="filtro_bloque" class="visually-hidden">Filtrar por bloque</label>
            <select id="filtro_bloque" name="id_bloque" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los bloques</option>
                @foreach ($bloques as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idBloque)>{{ $nombre }}</option>
                @endforeach
            </select>

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
                        <th scope="col">Actividad</th>
                        <th scope="col">Proceso</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Bloque</th>
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
                    @forelse ($actividades as $actividad)
                        <tr>
                            <td class="celda-titulo">{{ $actividad->nombre }}</td>
                            <td class="celda-detalle" data-etiqueta="Proceso">{{ $actividad->rutaDeProceso() ?: '—' }}</td>
                            <td class="celda-detalle" data-etiqueta="Tipo">{{ $actividad->tipo->value }}</td>
                            <td class="celda-detalle" data-etiqueta="Bloque">{{ $actividad->bloque->nombre }}</td>
                            @if (auth()->user()->esChazey())
                                <td class="celda-detalle" data-etiqueta="Empresa">{{ $actividad->empresa->nombre }}</td>
                            @endif
                            <td class="celda-estado"><x-estado :activo="$actividad->estado" texto-activo="Activa" /></td>
                            @can('administrar')
                                <td class="celda-acciones">
                                    <div class="acciones">
                                        @if ($actividad->estado)
                                            <a href="{{ route('actividades.editar', $actividad) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $actividad->id_actividad }}">Dar de baja</button>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reactivar-{{ $actividad->id_actividad }}">Reactivar</button>
                                        @endif
                                    </div>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="tabla-vacia">
                                {{ $buscar !== '' ? 'Ninguna actividad coincide con tu búsqueda.' : 'No hay actividades para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $actividades->total() }} {{ $actividades->total() === 1 ? 'actividad' : 'actividades' }}</span>
            {{ $actividades->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @can('administrar')
        @foreach ($actividades->where('estado', true) as $actividad)
            <x-confirmar id="baja-{{ $actividad->id_actividad }}" titulo="Dar de baja" :accion="route('actividades.baja', $actividad)" boton="Dar de baja">
                ¿Quieres dar de baja la actividad {{ $actividad->nombre }}?
                {{ \App\Models\Seguimiento::avisoDeBaja($actividad->seguimientos_count) }} Podrás reactivarla después.
            </x-confirmar>
        @endforeach

        @foreach ($actividades->where('estado', false) as $actividad)
            <x-confirmar id="reactivar-{{ $actividad->id_actividad }}" titulo="Reactivar" :accion="route('actividades.reactivar', $actividad)" boton="Reactivar">
                ¿Quieres reactivar la actividad {{ $actividad->nombre }}?
                @if ($faltantes[$actividad->id_actividad] > 0)
                    {{ \App\Models\Seguimiento::seGeneraran($faltantes[$actividad->id_actividad]) }} para los grupos activos que no la tienen.
                @else
                    No hace falta generar seguimientos nuevos.
                @endif
                Los existentes no cambian.
            </x-confirmar>
        @endforeach
    @endcan
@endsection
