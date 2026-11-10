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
        'proceso' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'aplicativo' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M3 9h18M6.5 6.8h.01M9.5 6.8h.01"/>',
        'actividad' => '<path d="M9 6h11.5M9 12h11.5M9 18h11.5"/><path d="m3.5 6 1.2 1.2L7 4.8M3.5 12l1.2 1.2L7 10.8M3.5 18l1.2 1.2L7 16.8"/>',
        'grupo' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-4 3-6 6.5-6s6 2 6.5 6"/><path d="M16 4.8a3.5 3.5 0 0 1 0 6.4M18.5 14.5c1.8.9 2.8 2.8 3 5.5"/>',
        'acceso' => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V7a3.5 3.5 0 0 1 6.8-1.2"/><path d="M12 14.5v2"/>',
        'participante' =>'<rect x="4" y="3.5" width="16" height="17" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7.5 17.5c.6-2.3 2.3-3.5 4.5-3.5s3.9 1.2 4.5 3.5"/>',
        'carga' =>'<path d="M12 15.5V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 14.5v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'seguimiento' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="m8.5 13 2.2 2.2L15.5 10.5"/>',
        'candado' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'completa' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c.6-4 3.6-6.5 7.5-6.5s6.9 2.5 7.5 6.5"/>',
        'llave' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16.5 6.5l2.5 2.5M14.5 8.5l2 2"/>',
        'menu' =>'<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
        'salir' => '<path d="M9.5 20.5h-4a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2h4"/><path d="m15.5 16.5 4.5-4.5-4.5-4.5M20 12H9.5"/>',
    };
@endphp

<svg width="{{ $tamano }}" height="{{ $tamano }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $trazos !!}</svg>
