{{--
    Alerta. Uso: <x-alerta tipo="error">Mensaje</x-alerta>
    tipo: error (por defecto), exito o advertencia.
--}}
@props(['tipo' => 'error'])

@php
    $clase = match ($tipo) {
        'error' => 'alert-danger',
        'exito' => 'alert-success',
        'advertencia' => 'alert-warning',
    };
@endphp

<div role="alert" {{ $attributes->class(['alert', $clase]) }}>{{ $slot }}</div>
