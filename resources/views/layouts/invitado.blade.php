{{-- Layout para pantallas de acceso: tarjeta centrada con el contenido a la izquierda y la marca a la derecha. --}}
@extends('layouts.base')

@section('cuerpo')
    <div class="acceso-fondo">
        <div class="acceso">
            <main class="acceso-principal">
                <div class="acceso-contenido">
                    @yield('contenido')
                </div>
            </main>

            {{-- En pantallas pequeñas esta sección pasa arriba, como una franja compacta. --}}
            <aside class="acceso-panel">
                <div class="marca-interior">
                    <div class="marca-logo-fondo">
                        <img src="{{ asset(config('marca.logo')) }}" alt="{{ config('marca.logo_alt') }}" class="marca-logo">
                    </div>
                    <p class="marca-nombre">{{ config('marca.nombre') }}</p>
                </div>
            </aside>
        </div>
    </div>
@endsection
