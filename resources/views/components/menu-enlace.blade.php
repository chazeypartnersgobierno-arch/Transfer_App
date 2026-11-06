{{--
    Opción del menú lateral. Se resalta sola cuando es la página actual.
    Uso: <x-menu-enlace ruta="empresas.index" activo-en="empresas.*" icono="empresa">Empresas</x-menu-enlace>
    activo-en indica qué rutas la resaltan; si no se pasa, solo la propia ruta.
--}}
@props(['ruta', 'icono', 'activoEn' => null])

@php
    $activo = request()->routeIs($activoEn ?? $ruta);
@endphp

<a href="{{ route($ruta) }}" @class(['menu-enlace', 'activo' => $activo]) @if ($activo) aria-current="page" @endif>
    <x-icono :nombre="$icono" />
    {{ $slot }}
</a>
