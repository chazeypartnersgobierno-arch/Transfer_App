@extends('layouts.app')

@section('titulo', 'Usuarios')

@section('contenido')
    <div class="pagina-encabezado">
        <div>
            <h1 class="pagina-titulo">Usuarios</h1>
            <p class="pagina-subtitulo">Quién entra a la app. El administrador ve todas las empresas; el consultor, la de su asignación activa.</p>
        </div>

        <a href="{{ route('usuarios.crear') }}" class="btn btn-primary">Nuevo usuario</a>
    </div>

    <div class="tarjeta">
        <form method="GET" action="{{ route('usuarios.index') }}" class="herramientas">
            <label for="buscar" class="visually-hidden">Buscar usuario</label>
            <input id="buscar" type="search" name="buscar" value="{{ $buscar }}" class="form-control buscador" placeholder="Buscar por nombre o correo">

            <label for="filtro_rol" class="visually-hidden">Filtrar por rol</label>
            <select id="filtro_rol" name="rol" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todos los roles</option>
                @foreach (\App\Enums\RolApp::cases() as $opcion)
                    <option value="{{ $opcion->value }}" @selected($opcion === $rol)>{{ $opcion->etiqueta() }}</option>
                @endforeach
            </select>

            <label for="filtro_empresa" class="visually-hidden">Filtrar por empresa asignada</label>
            <select id="filtro_empresa" name="id_empresa" class="form-select filtro" data-enviar-al-cambiar>
                <option value="">Todas las empresas</option>
                @foreach ($empresas as $id => $nombre)
                    <option value="{{ $id }}" @selected($id === $idEmpresa)>{{ $nombre }}</option>
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
                        <th scope="col">Empresa</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($usuarios as $usuario)
                        @php
                            $esConsultor = $usuario->rol_app === \App\Enums\RolApp::Consultor;
                            $esUnoMismo = $usuario->is(auth()->user());
                        @endphp
                        <tr>
                            <td class="celda-titulo">{{ $usuario->nombre }}</td>
                            <td class="celda-detalle">{{ $usuario->correo }}</td>
                            <td class="celda-detalle">{{ $usuario->rol_app->etiqueta() }}</td>
                            <td class="celda-detalle">
                                @if (! $esConsultor)
                                    Todas
                                @elseif ($usuario->asignacionesActivas->isEmpty())
                                    <span class="texto-secundario">Sin asignar</span>
                                @else
                                    {{ $usuario->asignacionesActivas->pluck('empresa.nombre')->implode(', ') }}
                                @endif
                            </td>
                            <td class="celda-estado"><x-estado :activo="$usuario->estado" /></td>
                            <td class="celda-acciones">
                                <div class="acciones">
                                    @if ($usuario->estado)
                                        <a href="{{ route('usuarios.editar', $usuario) }}" class="btn btn-sm btn-outline-primary">Editar</a>
                                    @endif
                                    @if ($esConsultor)
                                        <a href="{{ route('usuarios.asignaciones', $usuario) }}" class="btn btn-sm btn-outline-primary">Asignaciones</a>
                                    @endif
                                    @if ($usuario->estado && ! $esUnoMismo)
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#baja-{{ $usuario->id_usuario }}">Dar de baja</button>
                                    @elseif (! $usuario->estado)
                                        <form method="POST" action="{{ route('usuarios.reactivar', $usuario) }}" class="d-flex flex-fill">
                                            @csrf
                                            @method('PATCH')
                                            <x-boton variante="outline-primary" class="btn-sm flex-fill">Reactivar</x-boton>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tabla-vacia">
                                {{ $buscar !== '' ? 'Ningún usuario coincide con tu búsqueda.' : 'No hay usuarios para mostrar.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pie-tabla">
            <span>{{ $usuarios->total() }} {{ $usuarios->total() === 1 ? 'usuario' : 'usuarios' }}</span>
            {{ $usuarios->links() }}
        </div>
    </div>

    {{-- Las confirmaciones van fuera de la tabla para no romper su estructura. --}}
    @foreach ($usuarios->where('estado', true) as $usuario)
        @unless ($usuario->is(auth()->user()))
            <x-confirmar id="baja-{{ $usuario->id_usuario }}" titulo="Dar de baja" :accion="route('usuarios.baja', $usuario)" boton="Dar de baja">
                ¿Quieres dar de baja a {{ $usuario->nombre }}? Ya no podrá entrar y, si tiene la sesión abierta, saldrá en su siguiente clic. No se borra su información y podrás reactivarlo después.
            </x-confirmar>
        @endunless
    @endforeach
@endsection
