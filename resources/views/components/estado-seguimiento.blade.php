{{--
    Estado de un seguimiento, con la marca "Retrasado" cuando aplica (se calcula, no se guarda).
    Uso: <x-estado-seguimiento :seguimiento="$seguimiento" />
--}}
@props(['seguimiento'])

@php
    $estado = $seguimiento->estadoActual();
    $clase = match ($estado) {
        \App\Enums\EstadoActividad::Pendiente => 'pendiente',
        \App\Enums\EstadoActividad::EnCurso => 'en-curso',
        \App\Enums\EstadoActividad::Completada => 'completada',
    };
@endphp

<span class="estado estado-seguimiento-{{ $clase }}">{{ $estado->value }}</span>
@if ($seguimiento->estaRetrasado())
    <span class="etiqueta-retrasado">Retrasado</span>
@endif
