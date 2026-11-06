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
            </div>
        </nav>

        <main class="contenido">
            <div class="pagina">
                @yield('contenido')
            </div>
        </main>
    </div>
@endsection
