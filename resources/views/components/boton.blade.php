{{--
    Botón. Uso: <x-boton class="w-100">Entrar</x-boton>
    variante: primary (por defecto) u outline-primary.
--}}
@props(['type' => 'submit', 'variante' => 'primary'])

<button type="{{ $type }}" {{ $attributes->class(['btn', 'btn-'.$variante]) }}>{{ $slot }}</button>
