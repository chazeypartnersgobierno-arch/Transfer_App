{{-- Layout principal para pantallas con sesión: barra superior, menú lateral y contenido. --}}
@extends('layouts.base')

@section('cuerpo')
    {{-- La barra es blanca porque el logo va siempre sobre fondo blanco. --}}
    <header class="barra">
        {{-- Este botón solo aparece en pantallas pequeñas y abre el menú (offcanvas de Bootstrap). --}}
        <button class="barra-menu d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#menu" aria-controls="menu" aria-label="Abrir menú">
            <x-icono nombre="menu" :tamano="24" />
        </button>

        <a href="{{ route('inicio') }}">
            <img src="{{ asset(config('marca.logo')) }}" alt="{{ config('marca.logo_alt') }}" class="barra-logo">
        </a>

        <div class="barra-usuario">
            <div class="usuario-inicial" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->nombre, 0, 1)) }}</div>

            <div class="d-none d-md-block">
                <div class="usuario-nombre">{{ auth()->user()->nombre }}</div>
                <div class="usuario-rol">{{ auth()->user()->rol_app->etiqueta() }}</div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-boton variante="outline-primary" class="btn-salir" aria-label="Cerrar sesión">
                    <x-icono nombre="salir" :tamano="18" />
                    <span class="d-none d-md-inline">Cerrar sesión</span>
                </x-boton>
            </form>
        </div>
    </header>

    <div class="estructura">
        {{-- offcanvas-lg: en pantallas grandes queda fijo; en pequeñas se abre con el botón. --}}
        <nav class="offcanvas-lg offcanvas-start menu" tabindex="-1" id="menu" aria-label="Menú principal">
            <div class="offcanvas-header d-lg-none">
                <span class="fw-semibold">Menú</span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#menu" aria-label="Cerrar menú"></button>
            </div>

            <div class="offcanvas-body d-block">
                <ul class="menu-lista">
                    <li><x-menu-enlace ruta="inicio" icono="inicio">Inicio</x-menu-enlace></li>
                </ul>

                @can('dar-seguimiento')
                    <p class="menu-titulo">Seguimiento</p>
                    <ul class="menu-lista">
                        {{--
                            Seguimientos: una pantalla por fase (las da App\View\Composers\MenuDeSeguimientos).
                            <details> abre y cierra el submenú sin JavaScript; aparece abierto mientras se
                            trabaja en una fase. Si ya se está en la lista, al cambiar de fase se conservan los filtros.
                        --}}
                        @if ($menuFases->isNotEmpty())
                            <li>
                                @php($enEquipos = request()->routeIs('equipos.*'))
                                @php($abierto = $menuFaseActual !== null || $enEquipos)
                                <details class="menu-grupo" @if ($abierto) open @endif>
                                    <summary @class(['menu-enlace', 'menu-enlace-padre' => $abierto])>
                                        <x-icono nombre="seguimiento" />
                                        Seguimientos
                                        <span class="menu-flecha"><x-icono nombre="flecha" :tamano="16" /></span>
                                    </summary>
                                    <ul class="menu-sublista">
                                        @foreach ($menuFases as $menuFase)
                                            @php($activa = $menuFase->id_fase === $menuFaseActual)
                                            <li>
                                                <a href="{{ route('seguimientos.index', [...$menuFiltros, 'id_fase' => $menuFase->id_fase]) }}"
                                                    @class(['menu-enlace', 'menu-subenlace', 'activo' => $activa])
                                                    @if ($activa) aria-current="page" @endif>
                                                    <span class="menu-numero" aria-hidden="true">{{ $menuFase->orden }}</span>
                                                    {{ $menuFase->nombre }}
                                                </a>
                                            </li>
                                        @endforeach
                                        {{-- El equipo es del proceso, no de una fase: va aparte, con icono y sin número. --}}
                                        <li class="menu-separador" role="separator"></li>
                                        <li>
                                            <a href="{{ route('equipos.index') }}"
                                                @class(['menu-enlace', 'menu-subenlace', 'activo' => $enEquipos])
                                                @if ($enEquipos) aria-current="page" @endif>
                                                <x-icono nombre="grupo" :tamano="18" />
                                                Equipos de proceso
                                            </a>
                                        </li>
                                    </ul>
                                </details>
                            </li>
                        @else
                            <li><x-menu-enlace ruta="seguimientos.index" activo-en="seguimientos.*" icono="seguimiento">Seguimientos</x-menu-enlace></li>
                        @endif
                        @can('administrar-accesos')
                            <li><x-menu-enlace ruta="accesos.index" activo-en="accesos.*" icono="acceso">Accesos</x-menu-enlace></li>
                        @endcan
                    </ul>
                @endcan

                <p class="menu-titulo">Catálogos</p>
                <ul class="menu-lista">
                    {{-- Empresas y Actividades: solo admin (el consultor no las configura); la ruta también está protegida. --}}
                    @can('administrar-empresas')
                        <li><x-menu-enlace ruta="empresas.index" activo-en="empresas.*" icono="empresa">Empresas</x-menu-enlace></li>
                    @endcan
                    <li><x-menu-enlace ruta="fases.index" activo-en="fases.*" icono="fase">Fases</x-menu-enlace></li>
                    <li><x-menu-enlace ruta="procesos.index" activo-en="procesos.*" icono="proceso">Procesos</x-menu-enlace></li>
                    <li><x-menu-enlace ruta="grupos.index" activo-en="grupos.*" icono="grupo">Grupos</x-menu-enlace></li>
                    <li><x-menu-enlace ruta="participantes.index" activo-en="participantes.*" icono="participante">Participantes</x-menu-enlace></li>
                    <li><x-menu-enlace ruta="aplicativos.index" activo-en="aplicativos.*" icono="aplicativo">Aplicativos</x-menu-enlace></li>
                    @can('administrar')
                        <li><x-menu-enlace ruta="actividades.index" activo-en="actividades.*" icono="actividad">Actividades</x-menu-enlace></li>
                    @endcan
                </ul>

                {{-- Solo para admin; la ruta también está protegida en el servidor. --}}
                @can('administrar')
                    <p class="menu-titulo">Administración</p>
                    <ul class="menu-lista">
                        <li><x-menu-enlace ruta="usuarios.index" activo-en="usuarios.*" icono="usuario">Usuarios</x-menu-enlace></li>
                        <li><x-menu-enlace ruta="carga.index" activo-en="carga.*" icono="carga">Carga desde Excel</x-menu-enlace></li>
                    </ul>
                @endcan

                <p class="menu-titulo">Mi cuenta</p>
                <ul class="menu-lista">
                    <li><x-menu-enlace ruta="mi-contrasena.editar" activo-en="mi-contrasena.*" icono="llave">Mi contraseña</x-menu-enlace></li>
                </ul>
            </div>
        </nav>

        <main class="contenido">
            <div class="pagina">
                {{-- Mensajes que deja la acción anterior, por ejemplo al guardar. --}}
                @if (session('exito'))
                    <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
                @endif
                @if (session('error'))
                    <x-alerta tipo="error">{{ session('error') }}</x-alerta>
                @endif
                @if (session('aviso'))
                    <x-alerta tipo="advertencia">{{ session('aviso') }}</x-alerta>
                @endif

                @yield('contenido')
            </div>
        </main>
    </div>
@endsection
