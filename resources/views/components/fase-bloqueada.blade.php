{{--
    Aviso de fase bloqueada, con candado. El mensaje lo arma App\Services\AvanceDeFases::mensajeDeBloqueo().
    Uso: <x-fase-bloqueada :mensaje="$bloqueo" />
--}}
@props(['mensaje'])

<div role="alert" {{ $attributes->class(['alert', 'alert-warning', 'aviso-bloqueo']) }}>
    <x-icono nombre="candado" />
    <span>{{ $mensaje }}</span>
</div>
