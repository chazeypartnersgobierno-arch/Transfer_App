{{--
    Indicador de activo o de baja.
    Uso: <x-estado :activo="$empresa->estado" texto-activo="Activa" />
--}}
@props(['activo', 'textoActivo' => 'Activo', 'textoBaja' => 'De baja'])

<span @class(['estado', 'baja' => ! $activo])>{{ $activo ? $textoActivo : $textoBaja }}</span>
