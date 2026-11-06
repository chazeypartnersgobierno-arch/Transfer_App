{{--
    Opción del menú lateral. Se resalta sola cuando es la página actual.
    Uso: <x-menu-enlace ruta="inicio" icono="inicio">Inicio</x-menu-enlace>
--}}
@props(['ruta', 'icono'])

@php
    $activo = request()->routeIs($ruta);
@endphp

<a href="{{ route($ruta) }}" @class(['menu-enlace', 'activo' => $activo]) @if ($activo) aria-current="page" @endif>
    <x-icono :nombre="$icono" />
    {{ $slot }}
</a>
