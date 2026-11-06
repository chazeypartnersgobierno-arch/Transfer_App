{{--
    Icono en SVG, sin librerías externas. Toma el color del texto que lo rodea.
    Uso: <x-icono nombre="inicio" />. Para agregar uno, súmalo a la lista.
--}}
@props(['nombre', 'tamano' => 20])

@php
    $trazos = match ($nombre) {
        'inicio' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/>',
        'menu' => '<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
        'salir' => '<path d="M9.5 20.5h-4a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2h4"/><path d="m15.5 16.5 4.5-4.5-4.5-4.5M20 12H9.5"/>',
    };
@endphp

<svg width="{{ $tamano }}" height="{{ $tamano }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $trazos !!}</svg>
