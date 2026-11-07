{{--
    Icono en SVG, sin librerías externas. Toma el color del texto que lo rodea.
    Uso: <x-icono nombre="inicio" />. Para agregar uno, súmalo a la lista.
--}}
@props(['nombre', 'tamano' => 20])

@php
    $trazos = match ($nombre) {
        'inicio' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/>',
        'empresa' => '<path d="M4 20.5V5.5l9-2v17"/><path d="M13 9.5l7 2v9"/><path d="M2.5 20.5h19M7.5 8.5h2M7.5 12.5h2M7.5 16.5h2"/>',
        'fase' => '<circle cx="5" cy="12" r="2.5"/><circle cx="19" cy="12" r="2.5"/><path d="M7.5 12h9"/>',
        'bloque' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'aplicativo' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M3 9h18M6.5 6.8h.01M9.5 6.8h.01"/>',
        'actividad' => '<path d="M9 6h11.5M9 12h11.5M9 18h11.5"/><path d="m3.5 6 1.2 1.2L7 4.8M3.5 12l1.2 1.2L7 10.8M3.5 18l1.2 1.2L7 16.8"/>',
        'menu' => '<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
        'salir' => '<path d="M9.5 20.5h-4a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2h4"/><path d="m15.5 16.5 4.5-4.5-4.5-4.5M20 12H9.5"/>',
    };
@endphp

<svg width="{{ $tamano }}" height="{{ $tamano }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $trazos !!}</svg>
